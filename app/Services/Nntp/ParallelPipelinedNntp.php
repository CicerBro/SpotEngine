<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpDriverInterface;

/**
 * Parallel NNTP connection pool with pipelined HEAD requests.
 *
 * Drop-in replacement for ParallelNntp with one key difference: headParallel()
 * keeps $pipelineDepth HEAD requests in-flight on every connection simultaneously
 * instead of waiting for each response before sending the next.
 *
 * RFC 3977 §3.6 defines NNTP pipelining. The server processes commands in FIFO
 * order and responses arrive in the same order, so a per-socket queue is
 * sufficient to map incoming data back to the correct article.
 *
 * Limiting factors are mostly the number of connections and server throughput.
 */
class ParallelPipelinedNntp implements NntpDriverInterface
{
    /**
     * Headers consumed by SpotParser; all others are discarded to avoid
     * unnecessary iconv calls on MIME-encoded headers like Subject.
     */
    private const array WANTED_HEADERS = [
        'x-xml' => true,
        'x-xml-signature' => true,
        'x-user-key' => true,
        'message-id' => true,
        'from' => true,
        'date' => true,
    ];

    /** @var resource[] Raw sockets for parallel I/O */
    private array $sockets = [];

    /** Newsgroup currently selected on all connections (used when reconnecting dead sockets). */
    private string $currentGroup = '';

    private readonly int $numConnections;

    private readonly int $timeout;

    private readonly int $pipelineDepth;

    private int $readBufferSize = 65536;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config, int $numConnections = 20, int $pipelineDepth = 6)
    {
        $this->numConnections = max(1, min($numConnections, 200));
        $this->timeout = (int) ($this->config['timeout'] ?? 60);
        $this->pipelineDepth = max(1, min($pipelineDepth, 10));
    }

    /**
     * Initialize all connections in parallel.
     * For non-SSL: uses async TCP connect (very fast).
     * For SSL: opens connections sequentially (SSL handshake requires it).
     */
    public function connect(bool $showProgress = true): void
    {
        $useSSL = $this->config['ssl'] ?? true;
        $host = $this->config['host'];
        $port = $this->config['port'];

        if ($showProgress) {
            echo "Opening {$this->numConnections} connections to {$host}:{$port}... ";
            flush();
        }

        $startTime = microtime(true);

        if ($useSSL) {
            $this->connectSSL($host, $port);
        } else {
            $this->connectAsync($host, $port);
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        if ($showProgress) {
            echo \count($this->sockets)." ready ({$elapsed}s)\n";
        }

        if ($this->sockets === []) {
            throw new \RuntimeException('Failed to establish any connections');
        }
    }

    /**
     * Open connections asynchronously (non-SSL).
     * State machine: connecting → wait_greeting → wait_user → wait_pass → ready
     */
    private function connectAsync(string $host, int|string $port): void
    {
        $pending = [];

        for ($i = 0; $i < $this->numConnections; $i++) {
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );

            if ($socket) {
                stream_set_blocking($socket, false);
                $pending[$i] = ['socket' => $socket, 'state' => 'connecting'];
            }
        }

        $ready = [];
        $deadline = microtime(true) + 10;

        while ($pending !== [] && microtime(true) < $deadline) {
            $readSockets = [];
            $writeSockets = [];

            foreach ($pending as $idx => $data) {
                if ($data['state'] === 'connecting') {
                    $writeSockets[$idx] = $data['socket'];
                } else {
                    $readSockets[$idx] = $data['socket'];
                }
            }

            $r = $readSockets === [] ? null : array_values($readSockets);
            $w = $writeSockets === [] ? null : array_values($writeSockets);
            $e = null;
            $changed = @stream_select($r, $w, $e, 0, 100000);

            if ($changed === false) {
                break;
            }

            if ($changed === 0) {
                continue;
            }

            if ($w) {
                foreach ($w as $socket) {
                    foreach ($pending as $idx => $data) {
                        if ($data['socket'] === $socket && $data['state'] === 'connecting') {
                            $pending[$idx]['state'] = 'wait_greeting';
                            break;
                        }
                    }
                }
            }

            if ($r) {
                foreach ($r as $socket) {
                    foreach ($pending as $idx => $data) {
                        if ($data['socket'] !== $socket) {
                            continue;
                        }

                        $line = @fgets($socket, 4096);

                        if ($line === false || $line === '') {
                            @fclose($socket);
                            unset($pending[$idx]);
                            break;
                        }

                        $line = trim($line);

                        switch ($data['state']) {
                            case 'wait_greeting':
                                if (str_starts_with($line, '200') || str_starts_with($line, '201')) {
                                    if (! empty($this->config['username'])) {
                                        fwrite($socket, "AUTHINFO USER {$this->config['username']}\r\n");
                                        $pending[$idx]['state'] = 'wait_user';
                                    } else {
                                        $ready[] = $socket;
                                        unset($pending[$idx]);
                                    }
                                } else {
                                    @fclose($socket);
                                    unset($pending[$idx]);
                                }
                                break;

                            case 'wait_user':
                                if (str_starts_with($line, '381')) {
                                    fwrite($socket, "AUTHINFO PASS {$this->config['password']}\r\n");
                                    $pending[$idx]['state'] = 'wait_pass';
                                } elseif (str_starts_with($line, '281')) {
                                    $ready[] = $socket;
                                    unset($pending[$idx]);
                                } else {
                                    @fclose($socket);
                                    unset($pending[$idx]);
                                }
                                break;

                            case 'wait_pass':
                                if (str_starts_with($line, '281')) {
                                    $ready[] = $socket;
                                } else {
                                    @fclose($socket);
                                }
                                unset($pending[$idx]);
                                break;
                        }
                        break;
                    }
                }
            }
        }

        foreach ($pending as $data) {
            @fclose($data['socket']);
        }

        foreach ($ready as $socket) {
            stream_set_blocking($socket, true);
            stream_set_timeout($socket, $this->timeout);
            stream_set_read_buffer($socket, $this->readBufferSize);
            $this->sockets[] = $socket;
        }
    }

    /**
     * Open SSL connections in parallel using non-blocking I/O.
     * State machine: connecting → ssl_handshake → wait_greeting → wait_user → wait_pass → ready
     */
    private function connectSSL(string $host, int|string $port): void
    {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $pending = [];

        for ($i = 0; $i < $this->numConnections; $i++) {
            $socket = @stream_socket_client(
                "tcp://{$host}:{$port}",
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
                $context
            );

            if ($socket) {
                stream_set_blocking($socket, false);
                $pending[$i] = ['socket' => $socket, 'state' => 'connecting', 'retries' => 0];
            }
        }

        $ready = [];
        $deadline = microtime(true) + 15;

        while ($pending !== [] && microtime(true) < $deadline) {
            $all = array_column($pending, 'socket');
            $r = $all;
            $w = $all;
            $e = null;

            if (@stream_select($r, $w, $e, 0, 50000) === 0) {
                continue;
            }

            foreach ($pending as $idx => &$data) {
                $socket = $data['socket'];
                $inRead = \in_array($socket, $r, true);
                $inWrite = \in_array($socket, $w, true);

                switch ($data['state']) {
                    case 'connecting':
                        if ($inWrite) {
                            $data['state'] = 'ssl_handshake';
                        }
                        break;

                    case 'ssl_handshake':
                        $result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                        if ($result === true) {
                            $data['state'] = 'wait_greeting';
                            $data['retries'] = 0;
                        } elseif ($result === false) {
                            @fclose($socket);
                            unset($pending[$idx]);
                        }
                        break;

                    case 'wait_greeting':
                        if ($inRead) {
                            $line = @fgets($socket, 4096);

                            if ($line && trim($line) !== '') {
                                if (str_starts_with($line, '200') || str_starts_with($line, '201')) {
                                    if (! empty($this->config['username'])) {
                                        fwrite($socket, "AUTHINFO USER {$this->config['username']}\r\n");
                                        $data['state'] = 'wait_user';
                                        $data['retries'] = 0;
                                    } else {
                                        $ready[] = $socket;
                                        unset($pending[$idx]);
                                    }
                                } else {
                                    @fclose($socket);
                                    unset($pending[$idx]);
                                }
                            } else {
                                $data['retries']++;

                                if ($data['retries'] > 100) {
                                    @fclose($socket);
                                    unset($pending[$idx]);
                                }
                            }
                        }
                        break;

                    case 'wait_user':
                        if ($inRead) {
                            $line = @fgets($socket, 4096);

                            if ($line && trim($line) !== '') {
                                if (str_starts_with($line, '381')) {
                                    fwrite($socket, "AUTHINFO PASS {$this->config['password']}\r\n");
                                    $data['state'] = 'wait_pass';
                                    $data['retries'] = 0;
                                } elseif (str_starts_with($line, '281')) {
                                    $ready[] = $socket;
                                    unset($pending[$idx]);
                                } else {
                                    @fclose($socket);
                                    unset($pending[$idx]);
                                }
                            } else {
                                $data['retries']++;

                                if ($data['retries'] > 100) {
                                    @fclose($socket);
                                    unset($pending[$idx]);
                                }
                            }
                        }
                        break;

                    case 'wait_pass':
                        if ($inRead) {
                            $line = @fgets($socket, 4096);

                            if ($line && trim($line) !== '') {
                                if (str_starts_with($line, '281')) {
                                    $ready[] = $socket;
                                } else {
                                    @fclose($socket);
                                }
                                unset($pending[$idx]);
                            } else {
                                $data['retries']++;

                                if ($data['retries'] > 100) {
                                    @fclose($socket);
                                    unset($pending[$idx]);
                                }
                            }
                        }
                        break;
                }
            }
            unset($data);
        }

        foreach ($pending as $data) {
            @fclose($data['socket']);
        }

        foreach ($ready as $socket) {
            stream_set_blocking($socket, true);
            stream_set_timeout($socket, $this->timeout);
            stream_set_read_buffer($socket, $this->readBufferSize);
            $this->sockets[] = $socket;
        }
    }

    /**
     * Select group on all connections in parallel.
     *
     * @return array{count: int, first: int, last: int, group: string}
     */
    public function group(string $groupName): array
    {
        $this->currentGroup = $groupName;

        foreach ($this->sockets as $socket) {
            $this->sendCommand($socket, "GROUP $groupName");
        }

        $socketIdToIdx = [];

        foreach ($this->sockets as $idx => $socket) {
            $socketIdToIdx[(int) $socket] = $idx;
        }

        $result = null;
        $pending = array_keys($this->sockets);
        $deadline = microtime(true) + $this->timeout;

        while ($pending !== [] && microtime(true) < $deadline) {
            $read = array_map(fn (int $idx) => $this->sockets[$idx], $pending);
            $write = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1, 0) <= 0) {
                continue;
            }

            foreach ($read as $socket) {
                $idx = $socketIdToIdx[(int) $socket];
                $response = $this->readLine($socket);

                if ($response === false || ! str_starts_with($response, '211')) {
                    throw new \RuntimeException("Failed to select group: $response");
                }

                if ($result === null) {
                    $parts = explode(' ', $response);
                    $result = [
                        'count' => (int) ($parts[1] ?? 0),
                        'first' => (int) ($parts[2] ?? 0),
                        'last' => (int) ($parts[3] ?? 0),
                        'group' => $parts[4] ?? $groupName,
                    ];
                }

                $pending = array_values(array_diff($pending, [$idx]));
            }
        }

        if ($pending !== []) {
            throw new \RuntimeException('Timed out waiting for GROUP response on '.\count($pending).' connections');
        }

        return $result ?? ['count' => 0, 'first' => 0, 'last' => 0, 'group' => $groupName];
    }

    /**
     * Get XOVER data for a range using all connections in parallel.
     *
     * @return array<int, true>
     */
    public function xover(int $start, int $end): array
    {
        if ($this->sockets === []) {
            throw new \RuntimeException('Not connected');
        }

        $ranges = $this->splitRange($start, $end);

        $socketIdToIdx = [];

        foreach ($this->sockets as $idx => $socket) {
            $socketIdToIdx[(int) $socket] = $idx;
        }

        $pending = [];

        foreach ($ranges as $idx => [$rStart, $rEnd]) {
            $this->sendCommand($this->sockets[$idx], "XOVER $rStart-$rEnd");
            $pending[$idx] = ['state' => 'wait_response', 'buffer' => ''];
        }

        foreach (array_keys($pending) as $idx) {
            stream_set_blocking($this->sockets[$idx], false);
        }

        $articles = [];
        $deadline = microtime(true) + $this->timeout;

        while ($pending !== [] && microtime(true) < $deadline) {
            $readSet = array_map(fn (int $idx) => $this->sockets[$idx], array_keys($pending));
            $write = null;
            $except = null;

            if (@stream_select($readSet, $write, $except, 1, 0) <= 0) {
                continue;
            }

            foreach ($readSet as $socket) {
                $idx = $socketIdToIdx[(int) $socket];

                if (! isset($pending[$idx])) {
                    continue;
                }

                $data = @fread($socket, $this->readBufferSize);

                if ($data === false || $data === '') {
                    if ($data === false || feof($socket)) {
                        unset($pending[$idx]);
                    }

                    continue;
                }

                $pending[$idx]['buffer'] .= $data;

                while (isset($pending[$idx])) {
                    $newlinePos = strpos($pending[$idx]['buffer'], "\n");

                    if ($newlinePos === false) {
                        break;
                    }

                    $line = rtrim(substr($pending[$idx]['buffer'], 0, $newlinePos), "\r");
                    $pending[$idx]['buffer'] = substr($pending[$idx]['buffer'], $newlinePos + 1);

                    if ($pending[$idx]['state'] === 'wait_response') {
                        if (str_starts_with($line, '224')) {
                            $pending[$idx]['state'] = 'reading';
                        } elseif (str_starts_with($line, '420') || str_starts_with($line, '423')) {
                            unset($pending[$idx]);
                        } else {
                            foreach ($this->sockets as $s) {
                                stream_set_blocking($s, true);
                            }

                            throw new \RuntimeException("XOVER failed: $line");
                        }

                        continue;
                    }

                    if ($line === '.') {
                        unset($pending[$idx]);

                        continue;
                    }

                    $tabPos = strpos($line, "\t");

                    if ($tabPos !== false) {
                        $articles[(int) substr($line, 0, $tabPos)] = true;
                    }
                }
            }
        }

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, true);
        }

        return $articles;
    }

    /**
     * Fetch headers for multiple articles using pipelined parallel connections.
     *
     * Each connection maintains $pipelineDepth in-flight HEAD requests. As soon
     * as one response completes, the next command is sent without waiting for an
     * additional round trip. Responses arrive in FIFO order per connection, so a
     * per-socket queue is sufficient to track which article each response belongs to.
     *
     * @param  array<int>  $articleNumbers
     * @param  callable(?array<string,string>): void|null  $onArticle
     * @return array<int, array<string, string>|null>
     */
    public function headParallel(array $articleNumbers, bool $showProgress = true, ?callable $onArticle = null): array
    {
        if ($articleNumbers === []) {
            return [];
        }

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, false);
        }

        /** @var array<int, int> */
        $socketIdToIdx = [];

        foreach ($this->sockets as $idx => $socket) {
            $socketIdToIdx[(int) $socket] = $idx;
        }

        /** @var \SplQueue<int> */
        $queue = new \SplQueue;

        foreach ($articleNumbers as $num) {
            $queue->enqueue($num);
        }

        $total = $queue->count();
        $done = 0;
        $startTime = microtime(true);
        $results = [];

        $record = $onArticle !== null
            ? static function (int $num, ?array $headers) use ($onArticle): void {
                $onArticle($headers);
            }
        : static function (int $num, ?array $headers) use (&$results): void {
            $results[$num] = $headers;
        };

        /**
         * Per-socket pipeline: an ordered list of in-flight article states.
         * Index 0 is the article whose response is currently being parsed.
         * Higher indices have their HEAD commands already sent and are waiting.
         *
         * Each entry shape:
         *   articleNum: int
         *   status: 'wait_response'|'reading_headers'
         *   headers: array<string, string>
         *   currentHeader: string
         *   currentValue: string
         *
         * @var array<int, list<array<string, mixed>>>
         */
        $pipelines = [];

        /** @var array<int, string> */
        $buffers = [];

        /** @var array<int, float> Inactivity deadline per socket; absent when pipeline is empty. */
        $deadlines = [];

        // Send HEAD commands to top up a socket's pipeline to $pipelineDepth.
        $fillPipeline = function (int $idx) use ($queue, &$pipelines, &$deadlines): void {
            while (\count($pipelines[$idx]) < $this->pipelineDepth && ! $queue->isEmpty()) {
                $articleNum = $queue->dequeue();
                $pipelines[$idx][] = [
                    'articleNum' => $articleNum,
                    'status' => 'wait_response',
                    'headers' => [],
                    'currentHeader' => '',
                    'currentValue' => '',
                ];
                fwrite($this->sockets[$idx], "HEAD $articleNum\r\n");
            }

            if ($pipelines[$idx] !== []) {
                $deadlines[$idx] = microtime(true) + 3.0;
            } else {
                unset($deadlines[$idx]);
            }
        };

        // Initial fill: dispatch up to $pipelineDepth articles per socket immediately.
        foreach ($this->sockets as $idx => $socket) {
            $pipelines[$idx] = [];
            $buffers[$idx] = '';
            $fillPipeline($idx);
        }

        // Close a dead socket, requeue its in-flight articles for retry on the
        // replacement connection, then immediately refill the new pipeline.
        // Articles are re-enqueued rather than recorded as failures: the $done
        // counter only advances when an article is actually processed, so $total
        // remains correct and the main loop terminates cleanly.
        $replaceSocket = function (int $deadIdx) use (
            $queue,
            &$pipelines, &$socketIdToIdx, &$buffers, &$deadlines,
            $fillPipeline
        ): void {
            foreach ($pipelines[$deadIdx] as $item) {
                $queue->enqueue($item['articleNum']);
            }

            unset($socketIdToIdx[(int) $this->sockets[$deadIdx]]);
            @fclose($this->sockets[$deadIdx]);
            unset($this->sockets[$deadIdx], $pipelines[$deadIdx], $buffers[$deadIdx], $deadlines[$deadIdx]);

            $newSocket = $this->reconnectOne();

            if ($newSocket === null) {
                return;
            }

            $newIdx = ($this->sockets !== [] ? max(array_keys($this->sockets)) : -1) + 1;
            $this->sockets[$newIdx] = $newSocket;
            stream_set_blocking($newSocket, false);
            $socketIdToIdx[(int) $newSocket] = $newIdx;
            $pipelines[$newIdx] = [];
            $buffers[$newIdx] = '';
            $fillPipeline($newIdx);
        };

        // Main I/O loop.
        while ($done < $total) {
            $now = microtime(true);

            // Timeout detection. Snapshot keys to avoid mutation-during-iteration issues.
            foreach (array_keys($deadlines) as $idx) {
                if ($pipelines[$idx] !== [] && $now >= $deadlines[$idx]) {
                    $replaceSocket($idx);
                }
            }

            // Build read set from sockets with active pipelines.
            $readSet = [];

            foreach ($pipelines as $idx => $pipeline) {
                if ($pipeline !== [] && isset($this->sockets[$idx])) {
                    $readSet[] = $this->sockets[$idx];
                }
            }

            if ($readSet === []) {
                break; // Guard against infinite spin; $done < $total should not occur here.
            }

            $write = null;
            $except = null;

            if (@stream_select($readSet, $write, $except, 1, 0) <= 0) {
                continue;
            }

            foreach ($readSet as $socket) {
                $idx = $socketIdToIdx[(int) $socket] ?? null;

                if ($idx === null || ! isset($pipelines[$idx])) {
                    continue;
                }

                $data = @fread($socket, $this->readBufferSize);

                if ($data === false || $data === '') {
                    if ($data === false || feof($socket)) {
                        $replaceSocket($idx);
                    }

                    continue;
                }

                $deadlines[$idx] = microtime(true) + 3.0;
                $buffers[$idx] .= $data;

                // Parse all complete lines from the buffer.
                // $pipelines[$idx][0] is always the article whose response is arriving.
                // Completed articles are array_shift()ed; subsequent pipelines entries
                // automatically become [0] and are parsed from the same buffer in the
                // same loop iteration (a single fread may contain multiple responses).
                while ($pipelines[$idx] !== []) {
                    $newlinePos = strpos($buffers[$idx], "\n");

                    if ($newlinePos === false) {
                        break;
                    }

                    $line = rtrim(substr($buffers[$idx], 0, $newlinePos), "\r");
                    $buffers[$idx] = substr($buffers[$idx], $newlinePos + 1);

                    if ($pipelines[$idx][0]['status'] === 'wait_response') {
                        if (str_starts_with($line, '221')) {
                            $pipelines[$idx][0]['status'] = 'reading_headers';
                        } else {
                            // 430 No Such Article, or other error.
                            $record($pipelines[$idx][0]['articleNum'], null);
                            array_shift($pipelines[$idx]);
                            $done++;
                            $this->showProgress($done, $total, $startTime, $showProgress);
                            $fillPipeline($idx);
                        }

                        continue;
                    }

                    // status === 'reading_headers'
                    if ($line === '.') {
                        if ($pipelines[$idx][0]['currentHeader'] !== '') {
                            $pipelines[$idx][0]['headers'][$pipelines[$idx][0]['currentHeader']] =
                                $this->decodeHeader($pipelines[$idx][0]['currentValue']);
                        }

                        $record($pipelines[$idx][0]['articleNum'], $pipelines[$idx][0]['headers']);
                        array_shift($pipelines[$idx]);
                        $done++;
                        $this->showProgress($done, $total, $startTime, $showProgress);
                        $fillPipeline($idx);

                        continue;
                    }

                    // Folded continuation line (RFC 2822 header folding).
                    if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                        if ($pipelines[$idx][0]['currentHeader'] !== '') {
                            $pipelines[$idx][0]['currentValue'] .= ltrim($line);
                        }

                        continue;
                    }

                    // New header field.
                    $colonPos = strpos($line, ':');

                    if ($colonPos !== false) {
                        $name = strtolower(substr($line, 0, $colonPos));

                        if ($pipelines[$idx][0]['currentHeader'] !== '') {
                            $pipelines[$idx][0]['headers'][$pipelines[$idx][0]['currentHeader']] =
                                $this->decodeHeader($pipelines[$idx][0]['currentValue']);
                        }

                        if (isset(self::WANTED_HEADERS[$name])) {
                            $pipelines[$idx][0]['currentHeader'] = $name;
                            $pipelines[$idx][0]['currentValue'] = ltrim(substr($line, $colonPos + 1));
                        } else {
                            $pipelines[$idx][0]['currentHeader'] = '';
                            $pipelines[$idx][0]['currentValue'] = '';
                        }
                    }
                }
            }
        }

        $this->sockets = array_values($this->sockets);

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, true);
        }

        if ($showProgress) {
            echo "\r".str_repeat(' ', 60)."\r";
            flush();
        }

        return $results;
    }

    public function quit(): void
    {
        foreach ($this->sockets as $socket) {
            try {
                $this->sendCommand($socket, 'QUIT');
                fclose($socket);
            } catch (\Throwable) {
                // Ignore
            }
        }

        $this->sockets = [];
    }

    /**
     * Close all socket file descriptors without sending QUIT.
     *
     * Call this in a forked child process immediately after fork() to prevent
     * the child's destructors from sending QUIT on the parent's open sockets.
     */
    public function detach(): void
    {
        foreach ($this->sockets as $socket) {
            @fclose($socket);
        }

        $this->sockets = [];
    }

    public function getConnectionCount(): int
    {
        return \count($this->sockets);
    }

    /** @return array<int, array{int, int}> */
    private function splitRange(int $start, int $end): array
    {
        $total = $end - $start + 1;
        $numConns = min(\count($this->sockets), $total);
        $perConn = intdiv($total, $numConns);
        $remainder = $total % $numConns;

        $ranges = [];
        $cursor = $start;

        for ($i = 0; $i < $numConns; $i++) {
            $size = $perConn + ($i < $remainder ? 1 : 0);
            $ranges[$i] = [$cursor, $cursor + $size - 1];
            $cursor += $size;
        }

        return $ranges;
    }

    /** @param resource $socket */
    private function sendCommand($socket, string $command): void
    {
        fwrite($socket, "$command\r\n");
    }

    /** @param resource $socket */
    private function readLine($socket): string|false
    {
        $line = fgets($socket, $this->readBufferSize);

        if ($line === false) {
            return false;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Open a single replacement connection: TCP connect → authenticate → GROUP.
     *
     * @return resource|null
     */
    private function reconnectOne(): mixed
    {
        $useSSL = $this->config['ssl'] ?? true;
        $host = $this->config['host'];
        $port = $this->config['port'];
        $reconnectTimeout = 5;

        $context = $useSSL ? stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]) : null;

        $socket = @stream_socket_client(
            ($useSSL ? 'ssl' : 'tcp')."://{$host}:{$port}",
            $errno,
            $errstr,
            $reconnectTimeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (! $socket) {
            return null;
        }

        stream_set_timeout($socket, $reconnectTimeout);

        $line = $this->readLine($socket);

        if ($line === false || (! str_starts_with($line, '200') && ! str_starts_with($line, '201'))) {
            @fclose($socket);

            return null;
        }

        if (! empty($this->config['username'])) {
            fwrite($socket, "AUTHINFO USER {$this->config['username']}\r\n");
            $line = $this->readLine($socket);

            if ($line === false) {
                @fclose($socket);

                return null;
            }

            if (str_starts_with($line, '381')) {
                fwrite($socket, "AUTHINFO PASS {$this->config['password']}\r\n");
                $line = $this->readLine($socket);

                if ($line === false || ! str_starts_with($line, '281')) {
                    @fclose($socket);

                    return null;
                }
            } elseif (! str_starts_with($line, '281')) {
                @fclose($socket);

                return null;
            }
        }

        if ($this->currentGroup !== '') {
            fwrite($socket, "GROUP {$this->currentGroup}\r\n");
            $line = $this->readLine($socket);

            if ($line === false || ! str_starts_with($line, '211')) {
                @fclose($socket);

                return null;
            }
        }

        stream_set_timeout($socket, $this->timeout);
        stream_set_read_buffer($socket, $this->readBufferSize);

        return $socket;
    }

    private function decodeHeader(string $header): string
    {
        if (! str_contains($header, '=?')) {
            return $header;
        }

        $decoded = iconv_mime_decode($header, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $header;
    }

    private function showProgress(int $done, int $total, float $startTime, bool $showProgress): void
    {
        if (! $showProgress || ($done % 50 !== 0 && $done !== $total)) {
            return;
        }

        $elapsed = microtime(true) - $startTime;
        $rate = $elapsed > 0 ? round($done / $elapsed, 1) : 0;
        $pct = (int) round(100 * $done / $total);

        echo "\r  Progress: $done/$total ($pct%) - {$rate}/sec   ";
        flush();
    }

    public function __destruct()
    {
        $this->quit();
    }
}
