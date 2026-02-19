<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpDriverInterface;

/**
 * Parallel NNTP connection pool for high-throughput header retrieval.
 *
 * This class is standalone and completely independent from NntpClient — they
 * share no sockets, no state, and no base class. ParallelNntp maintains a pool
 * of N connections and uses non-blocking I/O (stream_select + fread) to fetch
 * thousands of article headers concurrently.
 */
class ParallelNntp implements NntpDriverInterface
{
    /**
     * Headers consumed by SpotParser. All other headers are silently discarded
     * during HEAD parsing — this eliminates iconv calls for Subject and other
     * MIME-encoded headers that the application never reads.
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

    private int $readBufferSize = 65536;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config, int $numConnections = 20)
    {
        // Cap at 200 to avoid hitting server connection limits
        $this->numConnections = max(1, min($numConnections, 200));
        $this->timeout = (int) ($this->config['timeout'] ?? 60);
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
     * Open connections asynchronously (for non-SSL).
     * Uses a state machine to handle all phases in parallel:
     * connecting -> wait_greeting -> wait_user -> wait_pass -> ready
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
     * State machine: connecting -> ssl_handshake -> wait_greeting -> wait_user -> wait_pass -> ready
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
     * Uses non-blocking fread() with per-socket buffers so one stream_select()
     * wakeup drains all available data rather than one line at a time.
     *
     * Returns a map of article-number => true. Only the keys are used downstream
     * (to drive headParallel); the value is a placeholder so callers can check
     * existence and count without allocating per-article arrays.
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

                    // Only the article number is needed downstream — skip all
                    // field parsing and the two expensive decodeHeader() calls.
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
     * Fetch headers for multiple articles using parallel connections.
     *
     * Uses non-blocking fread() with a per-socket buffer so each stream_select()
     * call drains all available data rather than one line at a time. Dead sockets
     * (EOF or timeout) are immediately replaced with fresh connections so the pool
     * size stays stable throughout the batch.
     *
     * @param  array<int>  $articleNumbers
     * @param  callable(?array<string,string>): void|null  $onArticle  Optional callback invoked for each
     *                                                                 completed article (headers array or null on failure). When provided the method returns []
     *                                                                 and never accumulates headers in memory, keeping peak usage proportional to connection
     *                                                                 count rather than batch size.
     * @return array<int, array<string, string>|null> Article number => headers (empty when $onArticle provided)
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

        $pending = [];
        $buffers = [];
        $states = [];
        $deadlines = [];

        // Replaces a dead socket slot with a fresh connection, then dispatches
        // the next article onto it. Keeps the pool size stable over long batches.
        $replaceSocket = function (int $deadIdx) use (&$socketIdToIdx, &$pending, &$buffers, &$states, &$deadlines, $queue): void {
            unset($this->sockets[$deadIdx]);

            $newSocket = $this->reconnectOne();

            if ($newSocket === null) {
                return; // Server refused reconnect — shrink pool temporarily
            }

            $newIdx = ($this->sockets !== [] ? max(array_keys($this->sockets)) : -1) + 1;
            $this->sockets[$newIdx] = $newSocket;
            stream_set_blocking($newSocket, false);
            $socketIdToIdx[(int) $newSocket] = $newIdx;

            if (! $queue->isEmpty()) {
                $next = $queue->dequeue();
                $pending[$newIdx] = $next;
                $buffers[$newIdx] = '';
                $states[$newIdx] = ['status' => 'wait_response', 'headers' => [], 'currentHeader' => '', 'currentValue' => ''];
                $deadlines[$newIdx] = microtime(true) + 3.0;
                fwrite($newSocket, "HEAD $next\r\n");
            }
        };

        foreach ($this->sockets as $idx => $socket) {
            if ($queue->isEmpty()) {
                break;
            }

            $articleNum = $queue->dequeue();
            $pending[$idx] = $articleNum;
            $buffers[$idx] = '';
            $states[$idx] = ['status' => 'wait_response', 'headers' => [], 'currentHeader' => '', 'currentValue' => ''];
            $deadlines[$idx] = microtime(true) + 3.0;
            fwrite($socket, "HEAD $articleNum\r\n");
        }

        while ($pending !== []) {
            $now = microtime(true);

            foreach ($deadlines as $idx => $deadline) {
                if (! isset($pending[$idx]) || $now < $deadline) {
                    continue;
                }

                $record($pending[$idx], null);
                $done++;

                // Socket timed out — in-flight response may still be arriving, so
                // reusing it would corrupt the NNTP stream. Close and reconnect.
                @fclose($this->sockets[$idx]);
                unset($pending[$idx], $deadlines[$idx]);
                $replaceSocket($idx);
            }

            if ($pending === []) {
                break;
            }

            $readSet = [];

            foreach ($pending as $idx => $_) {
                $readSet[] = $this->sockets[$idx];
            }

            $write = null;
            $except = null;

            if (@stream_select($readSet, $write, $except, 1, 0) <= 0) {
                continue;
            }

            foreach ($readSet as $socket) {
                $idx = $socketIdToIdx[(int) $socket];
                $data = @fread($socket, $this->readBufferSize);

                if ($data === false || $data === '') {
                    // fread returns '' for both "no data yet" (non-blocking) and EOF.
                    if ($data === false || feof($socket)) {
                        $record($pending[$idx], null);
                        @fclose($socket);
                        unset($pending[$idx], $deadlines[$idx]);
                        $done++;
                        $replaceSocket($idx);
                    }

                    continue;
                }

                $deadlines[$idx] = microtime(true) + 3.0;
                $buffers[$idx] .= $data;

                while (isset($pending[$idx])) {
                    $newlinePos = strpos($buffers[$idx], "\n");

                    if ($newlinePos === false) {
                        break;
                    }

                    $line = rtrim(substr($buffers[$idx], 0, $newlinePos), "\r");
                    $buffers[$idx] = substr($buffers[$idx], $newlinePos + 1);
                    $articleNum = $pending[$idx];

                    if ($states[$idx]['status'] === 'wait_response') {
                        if (str_starts_with($line, '221')) {
                            $states[$idx]['status'] = 'reading_headers';
                        } else {
                            $record($articleNum, null);
                            $this->headDispatchNext($idx, $socket, $pending, $states, $buffers, $deadlines, $queue, $done, $total, $startTime, $showProgress);
                        }

                        continue;
                    }

                    if ($line === '.') {
                        if ($states[$idx]['currentHeader'] !== '') {
                            $states[$idx]['headers'][$states[$idx]['currentHeader']] = $this->decodeHeader($states[$idx]['currentValue']);
                        }

                        $record($articleNum, $states[$idx]['headers']);
                        $this->headDispatchNext($idx, $socket, $pending, $states, $buffers, $deadlines, $queue, $done, $total, $startTime, $showProgress);

                        continue;
                    }

                    if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
                        // Folded continuation: only append when the current header is wanted.
                        if ($states[$idx]['currentHeader'] !== '') {
                            $states[$idx]['currentValue'] .= ltrim($line);
                        }

                        continue;
                    }

                    $colonPos = strpos($line, ':');

                    if ($colonPos !== false) {
                        $name = strtolower(substr($line, 0, $colonPos));

                        // Flush the previous header before switching.
                        if ($states[$idx]['currentHeader'] !== '') {
                            $states[$idx]['headers'][$states[$idx]['currentHeader']] = $this->decodeHeader($states[$idx]['currentValue']);
                        }

                        // Only track headers that SpotParser actually reads.
                        if (isset(self::WANTED_HEADERS[$name])) {
                            $states[$idx]['currentHeader'] = $name;
                            $states[$idx]['currentValue'] = ltrim(substr($line, $colonPos + 1));
                        } else {
                            $states[$idx]['currentHeader'] = '';
                            $states[$idx]['currentValue'] = '';
                        }
                    }
                }
            }
        }

        // Re-index: dead slots were unset inline, new reconnected sockets got higher
        // indices. Normalise to 0..N-1 so subsequent xover() calls work correctly.
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

    /**
     * Complete the current article on a socket and dispatch the next one.
     *
     * @param  array<int, int>  $pending
     * @param  array<int, array<string, mixed>>  $states
     * @param  array<int, string>  $buffers
     * @param  array<int, float>  $deadlines
     * @param  \SplQueue<int>  $queue
     * @param  resource  $socket
     */
    private function headDispatchNext(
        int $idx,
        mixed $socket,
        array &$pending,
        array &$states,
        array &$buffers,
        array &$deadlines,
        \SplQueue $queue,
        int &$done,
        int $total,
        float $startTime,
        bool $showProgress,
    ): void {
        $done++;

        if ($showProgress && ($done % 50 === 0 || $done === $total)) {
            $elapsed = microtime(true) - $startTime;
            $rate = $elapsed > 0 ? round($done / $elapsed, 1) : 0;
            $pct = (int) round(100 * $done / $total);
            echo "\r  Progress: $done/$total ($pct%) - {$rate}/sec   ";
            flush();
        }

        if (! $queue->isEmpty()) {
            $next = $queue->dequeue();
            $pending[$idx] = $next;
            $states[$idx] = ['status' => 'wait_response', 'headers' => [], 'currentHeader' => '', 'currentValue' => ''];
            $deadlines[$idx] = microtime(true) + 3.0;
            fwrite($socket, "HEAD $next\r\n");
        } else {
            unset($pending[$idx], $deadlines[$idx]);
        }
    }

    /**
     * Open a single replacement connection: TCP connect → authenticate → GROUP.
     *
     * Called synchronously when headParallel detects a dead socket. Takes ~100–300 ms,
     * during which OS buffers absorb data from the remaining active sockets — well
     * within typical buffer limits. Returns the ready socket resource, or null if the
     * reconnect fails (caller will simply shrink the pool by one slot).
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

    /**
     * Close all connections.
     */
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

    /**
     * Get number of active connections.
     */
    public function getConnectionCount(): int
    {
        return \count($this->sockets);
    }

    /**
     * Split an article range into sub-ranges, one per connection.
     *
     * @return array<int, array{int, int}>
     */
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

    /**
     * Send command to socket.
     *
     * @param  resource  $socket
     */
    private function sendCommand($socket, string $command): void
    {
        fwrite($socket, "$command\r\n");
    }

    /**
     * Read line from socket (blocking).
     *
     * @param  resource  $socket
     */
    private function readLine($socket): string|false
    {
        $line = fgets($socket, $this->readBufferSize);

        if ($line === false) {
            return false;
        }

        return rtrim($line, "\r\n");
    }

    /**
     * Decode RFC 2047 MIME-encoded words (e.g. =?UTF-8?B?...?= in From headers).
     * The early-return guard avoids iconv for plain ASCII values.
     */
    private function decodeHeader(string $header): string
    {
        if (! str_contains($header, '=?')) {
            return $header;
        }

        $decoded = iconv_mime_decode($header, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $header;
    }

    public function __destruct()
    {
        $this->quit();
    }
}
