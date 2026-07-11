<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpDriverInterface;

/**
 * Parallel NNTP connection pool for high-throughput header retrieval.
 *
 * Maintains a pool of N connections and uses non-blocking I/O (stream_select + fread)
 * to fetch thousands of article headers concurrently.
 */
class ParallelNntpDriver implements NntpDriverInterface
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

    private readonly NntpConnectionConfig $connectionConfig;

    private readonly NntpEndpoint $endpoint;

    private readonly SpotnetHeaderParser $headerParser;

    /** @param array<string, mixed> $config */
    public function __construct(
        private array $config,
        int $numConnections = 20,
        private readonly ?\Closure $connector = null,
    ) {
        $this->connectionConfig = NntpConnectionConfig::fromArray($config);
        $this->endpoint = $this->connectionConfig->primary;
        $this->headerParser = new SpotnetHeaderParser;
        $this->numConnections = max(1, min($numConnections, 200));
        $this->timeout = $this->endpoint->timeout;
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
            throw new NntpException('Failed to establish any NNTP connections', operation: 'connect');
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
                                        $this->sendCommand($socket, "AUTHINFO USER {$this->endpoint->username}");
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
                                    $this->sendCommand($socket, "AUTHINFO PASS {$this->endpoint->password}");
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
        $context = stream_context_create($this->endpoint->streamContextOptions());

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
                                        $this->sendCommand($socket, "AUTHINFO USER {$this->endpoint->username}");
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
                                    $this->sendCommand($socket, "AUTHINFO PASS {$this->endpoint->password}");
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
     * After collecting all GROUP responses, connections on stale load-balanced
     * backends are detected and closed. A backend is considered stale when its
     * last article number is more than STALE_THRESHOLD behind the maximum seen
     * across all connections. Stale connections would return 430 for any HEAD
     * request above their last article, silently dropping those spots.
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

        /** @var array<int, int> idx => last article number */
        $socketLast = [];
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
                    throw new NntpException("Failed to select group: $response", operation: 'GROUP');
                }

                $parts = explode(' ', $response);
                $last = (int) ($parts[3] ?? 0);
                $socketLast[$idx] = $last;

                // With load-balanced servers, different connections may report different
                // article ranges. Always use the response with the highest last-article
                // number so we don't miss recently-propagated articles.
                if ($result === null || $last > $result['last']) {
                    $result = [
                        'count' => (int) ($parts[1] ?? 0),
                        'first' => (int) ($parts[2] ?? 0),
                        'last' => $last,
                        'group' => $parts[4] ?? $groupName,
                    ];
                }

                $pending = array_values(array_diff($pending, [$idx]));
            }
        }

        if ($pending !== []) {
            throw new NntpException(
                'Timed out waiting for GROUP response on '.\count($pending).' connections',
                operation: 'GROUP',
                timedOut: true,
            );
        }

        // Drop connections on clearly stale backends (more than 100 articles behind
        // the freshest connection). Then report last = min(remaining), so every
        // active connection can serve the full range. This prevents parallel XOVER
        // slices from silently omitting articles that a slightly-behind connection
        // hasn't received yet; those articles are deferred to the next run instead.
        if ($result !== null) {
            $maxLast = $result['last'];
            $dropped = [];

            foreach ($socketLast as $idx => $last) {
                if ($last < $maxLast - 100) {
                    @fclose($this->sockets[$idx]);
                    unset($this->sockets[$idx]);
                    $dropped[] = $idx;
                }
            }

            if ($dropped !== []) {
                $this->sockets = array_values($this->sockets);
                echo '  (dropped '.\count($dropped).' stale backend connection(s), '.count($this->sockets)." remaining)\n";
                flush();
            }

            $remainingLasts = array_values(array_diff_key($socketLast, array_flip($dropped)));

            if ($remainingLasts !== []) {
                $result['last'] = min($remainingLasts);
            }
        }

        return $result ?? ['count' => 0, 'first' => 0, 'last' => 0, 'group' => $groupName];
    }

    /**
     * Fetch XOVER data for a range in parallel across all connections.
     *
     * The range is divided into equal slices — one per connection. Every
     * connection sends its XOVER command simultaneously and responses are
     * collected with stream_select. After group() dropped stale backends,
     * all remaining connections serve the same article range, so slice
     * assignment is safe.
     *
     * @return array<int, array{subject: string, from: string, date: string, message_id: string}>
     */
    public function xover(int $start, int $end): array
    {
        if ($this->sockets === []) {
            throw new NntpException('Not connected', operation: 'XOVER');
        }

        $numSockets = \count($this->sockets);
        $total = $end - $start + 1;
        $sliceSize = (int) ceil($total / $numSockets);

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, false);
        }

        // Assign slices and send all XOVER commands simultaneously.
        $slices = [];
        $pos = $start;

        foreach ($this->sockets as $idx => $socket) {
            if ($pos > $end) {
                break;
            }

            $sliceEnd = min($pos + $sliceSize - 1, $end);
            $this->sendCommand($socket, "XOVER $pos-$sliceEnd");
            $slices[$idx] = true;
            $pos = $sliceEnd + 1;
        }

        // Read all responses in parallel.
        $results = [];
        $buffers = array_fill_keys(array_keys($slices), '');
        $states = array_fill_keys(array_keys($slices), 'wait_response');
        $active = array_keys($slices);

        $socketIdToIdx = [];

        foreach ($this->sockets as $idx => $socket) {
            $socketIdToIdx[(int) $socket] = $idx;
        }

        $deadline = microtime(true) + $this->timeout;

        while ($active !== [] && microtime(true) < $deadline) {
            $readSet = array_values(array_intersect_key($this->sockets, array_flip($active)));
            $write = null;
            $except = null;

            if (@stream_select($readSet, $write, $except, 1, 0) <= 0) {
                continue;
            }

            foreach ($readSet as $socket) {
                $idx = $socketIdToIdx[(int) $socket];
                $data = @fread($socket, $this->readBufferSize);

                if ($data === false || ($data === '' && feof($socket))) {
                    throw new NntpException('NNTP connection closed during incomplete XOVER response', operation: 'XOVER');
                }

                if ($data === '') {
                    continue;
                }

                $buffers[$idx] .= $data;

                while (true) {
                    $newlinePos = strpos($buffers[$idx], "\n");

                    if ($newlinePos === false) {
                        break;
                    }

                    $line = rtrim(substr($buffers[$idx], 0, $newlinePos), "\r");
                    $buffers[$idx] = substr($buffers[$idx], $newlinePos + 1);

                    if ($states[$idx] === 'wait_response') {
                        if (str_starts_with($line, '224')) {
                            $states[$idx] = 'reading';
                        } else {
                            throw new NntpException("XOVER failed: {$line}", statusText: $line, operation: 'XOVER');
                        }

                        break;
                    }

                    if ($line === '.') {
                        $active = array_values(array_diff($active, [$idx]));
                        break;
                    }

                    // XOVER format: num\tsubject\tfrom\tdate\tmessage-id\treferences\tbytes\tlines
                    $parts = explode("\t", $line);

                    if (\count($parts) >= 5) {
                        $articleNum = (int) $parts[0];
                        $results[$articleNum] = [
                            'subject' => $parts[1],
                            'from' => $parts[2],
                            'date' => $parts[3],
                            'message_id' => trim($parts[4], '<>'),
                        ];
                    }
                }
            }
        }

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, true);
        }

        if ($active !== []) {
            throw new NntpException(
                'Timed out waiting for incomplete XOVER response on '.\count($active).' connections',
                operation: 'XOVER',
                timedOut: true,
            );
        }

        return $results;
    }

    /**
     * Fetch headers for multiple articles using parallel connections.
     *
     * Uses non-blocking fread() with a per-socket buffer so each stream_select()
     * call drains all available data rather than one line at a time. Dead sockets
     * (EOF or timeout) are immediately replaced with fresh connections so the pool
     * size stays stable throughout the batch.
     *
     * @param  array<int|string>  $articles  Article numbers (int) or message-IDs (string, without angle brackets)
     * @param  callable(?array<string,string>): void|null  $onArticle  Optional callback invoked for each
     *                                                                 completed article (headers array or null on failure). When provided the method returns []
     *                                                                 and never accumulates headers in memory, keeping peak usage proportional to connection
     *                                                                 count rather than batch size.
     * @return array<int|string, array<string, string>|null> Article number/message-ID => headers (empty when $onArticle provided)
     */
    public function headBatch(array $articles, bool $showProgress = true, ?callable $onArticle = null): array
    {
        if ($articles === []) {
            return [];
        }

        // Format an article number or message-ID into the string sent to the server.
        $headCmd = static fn (int|string $id): string => is_int($id) ? "HEAD $id\r\n" : "HEAD <$id>\r\n";

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, false);
        }

        /** @var array<int, int> */
        $socketIdToIdx = [];

        foreach ($this->sockets as $idx => $socket) {
            $socketIdToIdx[(int) $socket] = $idx;
        }

        /** @var \SplQueue<int|string> */
        $queue = new \SplQueue;

        foreach ($articles as $id) {
            $queue->enqueue($id);
        }

        $total = $queue->count();
        $done = 0;
        $startTime = microtime(true);

        $results = [];

        $record = $onArticle !== null
            ? static function (int|string $num, ?array $headers) use ($onArticle): void {
                $onArticle($headers);
            }
        : static function (int|string $num, ?array $headers) use (&$results): void {
            $results[$num] = $headers;
        };

        $pending = [];
        $buffers = [];
        $states = [];
        $deadlines = [];

        // Replaces a dead socket slot with a fresh connection, then dispatches
        // the next article onto it. Keeps the pool size stable over long batches.
        $replaceSocket = function (int $deadIdx) use (&$socketIdToIdx, &$pending, &$buffers, &$states, &$deadlines, $queue, $headCmd): void {
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
                $states[$newIdx] = ['status' => 'wait_response', 'parser' => $this->headerParser->start()];
                $deadlines[$newIdx] = microtime(true) + 3.0;
                $this->sendCommand($newSocket, rtrim($headCmd($next), "\r\n"));
            }
        };

        foreach ($this->sockets as $idx => $socket) {
            if ($queue->isEmpty()) {
                break;
            }

            $articleNum = $queue->dequeue();
            $pending[$idx] = $articleNum;
            $buffers[$idx] = '';
            $states[$idx] = ['status' => 'wait_response', 'parser' => $this->headerParser->start()];
            $deadlines[$idx] = microtime(true) + 3.0;
            $this->sendCommand($socket, rtrim($headCmd($articleNum), "\r\n"));
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

            foreach (array_keys($pending) as $idx) {
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
                            $this->headDispatchNext($idx, $socket, $pending, $states, $deadlines, $queue, $done, $total, $startTime, $showProgress);
                        }

                        continue;
                    }

                    if ($line === '.') {
                        $record($articleNum, $this->headerParser->finish($states[$idx]['parser']));
                        $this->headDispatchNext($idx, $socket, $pending, $states, $deadlines, $queue, $done, $total, $startTime, $showProgress);

                        continue;
                    }

                    $this->headerParser->consume($states[$idx]['parser'], $line, self::WANTED_HEADERS);
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
    /**
     * @param  \SplQueue<int|string>  $queue
     */
    private function headDispatchNext(
        int $idx,
        mixed $socket,
        array &$pending,
        array &$states,
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
            $states[$idx] = ['status' => 'wait_response', 'parser' => $this->headerParser->start()];
            $deadlines[$idx] = microtime(true) + 3.0;
            $headId = is_int($next) ? (string) $next : "<$next>";
            $this->sendCommand($socket, "HEAD $headId");
        } else {
            unset($pending[$idx], $deadlines[$idx]);
        }
    }

    /**
     * Open a single replacement connection: TCP connect → authenticate → GROUP.
     *
     * Called synchronously when headBatch detects a dead socket. Takes ~100–300 ms,
     * during which OS buffers absorb data from the remaining active sockets — well
     * within typical buffer limits. Returns the ready socket resource, or null if the
     * reconnect fails (caller will simply shrink the pool by one slot).
     *
     * @return resource|null
     */
    private function reconnectOne(): mixed
    {
        $reconnectTimeout = 5;
        $socket = $this->connector instanceof \Closure
            ? ($this->connector)($this->endpoint, $reconnectTimeout)
            : @stream_socket_client(
                $this->endpoint->address(),
                $errno,
                $errstr,
                $reconnectTimeout,
                STREAM_CLIENT_CONNECT,
                stream_context_create($this->endpoint->streamContextOptions()),
            );

        if (! $socket) {
            return null;
        }

        stream_set_timeout($socket, $reconnectTimeout);
        $protocol = NntpProtocol::forResource($socket, $this->readBufferSize);

        try {
            $response = $protocol->readResponse();

            if (! $response->is(ResponseCode::ReadyPostingAllowed, ResponseCode::ReadyPostingProhibited)) {
                throw NntpException::unexpected('connect', $response);
            }

            if ($this->endpoint->username !== '' && $this->endpoint->username !== '0') {
                $response = $protocol->command("AUTHINFO USER {$this->endpoint->username}");

                if ($response->is(ResponseCode::AuthenticationContinue)) {
                    $response = $protocol->command("AUTHINFO PASS {$this->endpoint->password}");
                }

                if (! $response->is(ResponseCode::AuthenticationAccepted)) {
                    throw NntpException::unexpected('AUTHINFO', $response);
                }
            }

            if ($this->currentGroup !== '') {
                $response = $protocol->command("GROUP {$this->currentGroup}");

                if (! $response->is(ResponseCode::GroupSelected)) {
                    throw NntpException::unexpected('GROUP', $response);
                }
            }
        } catch (NntpException) {
            @fclose($socket);

            return null;
        }

        stream_set_timeout($socket, $this->timeout);
        stream_set_read_buffer($socket, $this->readBufferSize);

        return $socket;
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

    /** @param resource $socket */
    private function sendCommand($socket, string $command): void
    {
        NntpProtocol::forResource($socket, $this->readBufferSize)->writeCommand($command);
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

    public function __destruct()
    {
        $this->quit();
    }
}
