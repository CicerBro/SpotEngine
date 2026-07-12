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
    private const int MAX_XOVER_RETRIES = 2;

    /**
     * HEAD responses are small and this is an absolute request deadline, not an
     * inactivity timeout. Partial header bytes must not extend a stalled request.
     */
    private const float HEAD_RESPONSE_DEADLINE_SECONDS = 1.0;

    private const int RECONNECT_TIMEOUT_SECONDS = 1;

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

    /** Set when quit()/detach() is called so in-flight batch loops can exit cleanly. */
    private bool $quitting = false;

    /** Newsgroup currently selected on all connections (used when reconnecting dead sockets). */
    private string $currentGroup = '';

    private readonly int $numConnections;

    private readonly int $timeout;

    /** Per-HEAD response deadline before retrying on a fresh connection. */
    private float $headResponseDeadlineSeconds;

    private int $readBufferSize = 65536;

    private readonly NntpConnectionConfig $connectionConfig;

    private readonly NntpEndpoint $endpoint;

    private readonly SpotnetHeaderParser $headerParser;

    /** @param array<string, mixed> $config */
    public function __construct(
        private array $config,
        int $numConnections = 20,
        private readonly ?\Closure $connector = null,
        ?float $headResponseDeadlineSeconds = null,
    ) {
        $this->connectionConfig = NntpConnectionConfig::fromArray($config);
        $this->endpoint = $this->connectionConfig->primary;
        $this->headerParser = new SpotnetHeaderParser;
        $this->numConnections = max(1, min($numConnections, 200));
        $this->timeout = $this->endpoint->timeout;
        $this->headResponseDeadlineSeconds = max(0.0, $headResponseDeadlineSeconds ?? self::HEAD_RESPONSE_DEADLINE_SECONDS);
    }

    public function __destruct()
    {
        $this->quit();
    }

    /**
     * Initialize all connections in parallel.
     * For non-SSL: uses async TCP connect (very fast).
     * For SSL: opens connections sequentially (SSL handshake requires it).
     */
    public function connect(bool $showProgress = true): void
    {
        $this->quitting = false;

        $useSSL = $this->config['ssl'] ?? true;
        $host = $this->config['host'];
        $port = $this->config['port'];

        if ($showProgress) {
            echo "Opening {$this->numConnections} connections to {$host}:{$port}... ";
            flush();
        }

        $startTime = microtime(true);

        if ($this->connector instanceof \Closure) {
            for ($i = 0; $i < $this->numConnections; $i++) {
                $socket = $this->reconnectOne();

                if ($socket !== null) {
                    $this->sockets[] = $socket;
                }
            }
        } else {
            $this->connectAsync($host, $port, $useSSL);
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        if ($showProgress) {
            echo \count($this->sockets) . " ready ({$elapsed}s)\n";
        }

        if ($this->sockets === []) {
            throw new NntpException('Failed to establish any NNTP connections', operation: 'connect');
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
                'Timed out waiting for GROUP response on ' . \count($pending) . ' connections',
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
                echo '  (dropped ' . \count($dropped) . ' stale backend connection(s), ' . count($this->sockets) . " remaining)\n";
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
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->xoverAttempt($start, $end);
            } catch (NntpException $exception) {
                $isIncompleteResponse = str_contains($exception->getMessage(), 'incomplete XOVER response');
                $canRetry = $exception->operation === 'XOVER'
                    && ($exception->timedOut || $isIncompleteResponse)
                    && $attempt < self::MAX_XOVER_RETRIES
                    && $this->sockets !== [];

                if (! $canRetry) {
                    throw $exception;
                }

                $retry = $attempt + 1;
                echo "  XOVER {$start}-{$end} was interrupted; retrying batch {$retry}/" . self::MAX_XOVER_RETRIES
                    . ' with ' . \count($this->sockets) . " connection(s)...\n";
                flush();
            }
        }
    }

    /**
     * Fetch headers for multiple articles using parallel connections.
     *
     * A callback receives exactly one typed result for each completed article.
     * A fatal protocol, authentication, or reconnect failure throws instead, so
     * pending articles remain untouched by callers such as spot:enrich.
     *
     * @param  array<int|string>  $articles
     * @param  callable(int|string, HeadBatchResult): void|null  $onArticle
     * @return array<int|string, array<string, string>|null>
     */
    public function headBatch(array $articles, bool $showProgress = true, ?callable $onArticle = null): array
    {
        if ($articles === []) {
            return [];
        }

        if ($this->sockets === []) {
            throw new NntpException('Not connected', operation: 'HEAD');
        }

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, false);
        }

        /** @var \SplQueue<int|string> $queue */
        $queue = new \SplQueue;

        foreach ($articles as $id) {
            $queue->enqueue($id);
        }

        $total = $queue->count();
        $done = 0;
        $startTime = microtime(true);
        $results = [];
        $completed = [];
        $retryCounts = [];
        $pending = [];
        $buffers = [];
        $states = [];
        $deadlines = [];
        $reconnectsNeeded = 0;
        $socketIdToIdx = [];

        foreach ($this->sockets as $idx => $socket) {
            $socketIdToIdx[(int) $socket] = $idx;
        }

        $record = function (int|string $articleId, HeadBatchResult $result) use (&$completed, &$results, $onArticle): void {
            $key = $this->headRetryKey($articleId);

            if (isset($completed[$key])) {
                return;
            }

            $completed[$key] = true;

            if ($onArticle !== null) {
                $onArticle($articleId, $result);

                return;
            }

            $results[$articleId] = $result->headers;
        };

        $markDone = function () use (&$done, $total, $startTime, $showProgress): void {
            $done++;

            if ($showProgress && ($done % 50 === 0 || $done === $total)) {
                $elapsed = microtime(true) - $startTime;
                $rate = $elapsed > 0 ? round($done / $elapsed, 1) : 0;
                $percentage = (int) round(100 * $done / $total);
                echo "\r  Progress: $done/$total ($percentage%) - {$rate}/sec   ";
                flush();
            }
        };

        $complete = function (int $idx, mixed $socket, HeadBatchResult $result) use (&$pending, &$states, &$deadlines, &$buffers, $queue, $record, $markDone): void {
            $articleId = $pending[$idx];
            unset($pending[$idx], $states[$idx], $deadlines[$idx], $buffers[$idx]);
            $record($articleId, $result);
            $markDone();
            $this->dispatchNextHead($idx, $socket, $pending, $states, $deadlines, $buffers, $queue);
        };

        $retire = function (int $idx) use (&$pending, &$states, &$deadlines, &$buffers, &$retryCounts, &$reconnectsNeeded, $queue, $record, $markDone): void {
            $articleId = $pending[$idx];
            $retryKey = $this->headRetryKey($articleId);
            $this->closeSocketAt($idx);
            unset($pending[$idx], $states[$idx], $deadlines[$idx], $buffers[$idx]);

            if (($retryCounts[$retryKey] ?? 0) === 0) {
                $retryCounts[$retryKey] = 1;
                $queue->unshift($articleId);
                $reconnectsNeeded++;

                return;
            }

            $record($articleId, HeadBatchResult::timedOutAfterRetry());
            $markDone();
        };

        foreach ($this->sockets as $idx => $socket) {
            if (! $this->dispatchNextHead($idx, $socket, $pending, $states, $deadlines, $buffers, $queue)) {
                break;
            }
        }

        try {
            while ($pending !== [] || ! $queue->isEmpty() || $reconnectsNeeded > 0) {
                if ($this->quitting) {
                    break;
                }

                $now = microtime(true);

                foreach ($deadlines as $idx => $deadline) {
                    if (isset($pending[$idx]) && $now >= $deadline) {
                        $retire($idx);
                    }
                }

                if ($pending === [] && $reconnectsNeeded > 0) {
                    /*
                     * Reconnect only when there are no active HEAD reads. A bounded
                     * reconnect cannot stall healthy sockets or their read loop.
                     */
                    $replacement = $this->reconnectOne();

                    if ($replacement === null) {
                        throw new NntpException('Failed to reconnect a retired NNTP HEAD socket', operation: 'HEAD');
                    }

                    $reconnectsNeeded--;
                    $newIdx = ($this->sockets === [] ? -1 : max(array_keys($this->sockets))) + 1;
                    $this->sockets[$newIdx] = $replacement;
                    stream_set_blocking($replacement, false);
                    $socketIdToIdx[(int) $replacement] = $newIdx;
                    $this->dispatchNextHead($newIdx, $replacement, $pending, $states, $deadlines, $buffers, $queue);

                    continue;
                }

                $this->redistributeHeadQueue($pending, $states, $deadlines, $buffers, $queue);

                if ($pending === []) {
                    if ($queue->isEmpty()) {
                        break;
                    }

                    throw new NntpException('No NNTP sockets are available for HEAD requests', operation: 'HEAD');
                }

                $readSet = [];

                foreach (array_keys($pending) as $idx) {
                    if (isset($this->sockets[$idx])) {
                        $readSet[] = $this->sockets[$idx];
                    }
                }

                if ($readSet === []) {
                    throw new NntpException('No active NNTP sockets remain for HEAD requests', operation: 'HEAD');
                }

                $nextDeadline = min($deadlines);
                $waitMicroseconds = max(0, min(1_000_000, (int) (($nextDeadline - microtime(true)) * 1_000_000)));
                $write = null;
                $except = null;

                if (@stream_select($readSet, $write, $except, 0, $waitMicroseconds) <= 0) {
                    continue;
                }

                foreach ($readSet as $socket) {
                    $idx = $socketIdToIdx[(int) $socket] ?? null;

                    if ($idx === null || ! isset($pending[$idx])) {
                        continue;
                    }

                    $data = @fread($socket, $this->readBufferSize);

                    if ($data === false || ($data === '' && feof($socket))) {
                        $retire($idx);

                        continue;
                    }

                    if ($data === '') {
                        continue;
                    }

                    $buffers[$idx] .= $data;

                    while (isset($pending[$idx])) {
                        $newlinePos = strpos($buffers[$idx], "\n");

                        if ($newlinePos === false) {
                            break;
                        }

                        $line = rtrim(substr($buffers[$idx], 0, $newlinePos), "\r");
                        $buffers[$idx] = substr($buffers[$idx], $newlinePos + 1);

                        if ($states[$idx]['status'] === 'wait_response') {
                            if (str_starts_with($line, '221')) {
                                $states[$idx]['status'] = 'reading_headers';

                                continue;
                            }

                            if (preg_match('/^430(?:\s|$)/', $line) === 1) {
                                $complete($idx, $socket, HeadBatchResult::missing());

                                continue;
                            }

                            if ($onArticle === null) {
                                $complete($idx, $socket, HeadBatchResult::missing());

                                continue;
                            }

                            throw new NntpException("HEAD failed: {$line}", responseCode: (int) substr($line, 0, 3), statusText: $line, operation: 'HEAD');
                        }

                        if ($line === '.') {
                            $complete($idx, $socket, HeadBatchResult::success(
                                $this->headerParser->finish($states[$idx]['parser']),
                            ));

                            continue;
                        }

                        $this->headerParser->consume($states[$idx]['parser'], $line, self::WANTED_HEADERS);
                    }
                }
            }
        } finally {
            $this->sockets = array_values($this->sockets);

            foreach ($this->sockets as $socket) {
                stream_set_blocking($socket, true);
            }

            if ($showProgress) {
                echo "\r" . str_repeat(' ', 60) . "\r";
                flush();
            }
        }

        return $results;
    }

    public function quit(): void
    {
        $this->quitting = true;

        foreach ($this->sockets as $socket) {
            try {
                if (is_resource($socket)) {
                    $this->sendCommand($socket, 'QUIT');
                    @fclose($socket);
                }
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
        $this->quitting = true;

        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }

        $this->sockets = [];
    }

    public function getConnectionCount(): int
    {
        return \count($this->sockets);
    }

    /**
     * Fetch one XOVER attempt. Incomplete sockets are discarded before throwing
     * so a subsequent attempt cannot consume the remainder of an earlier response.
     *
     * @return array<int, array{subject: string, from: string, date: string, message_id: string}>
     */
    private function xoverAttempt(int $start, int $end): array
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
                    $failedConnections = \count($active);
                    $this->dropIncompleteXoverSockets($active);

                    throw new NntpException(
                        "NNTP connection closed during incomplete XOVER response on {$failedConnections} connections",
                        operation: 'XOVER',
                    );
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

                        continue;
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

        if ($active !== []) {
            $failedConnections = \count($active);
            $this->dropIncompleteXoverSockets($active);

            throw new NntpException(
                "Timed out waiting for incomplete XOVER response on {$failedConnections} connections",
                operation: 'XOVER',
                timedOut: true,
            );
        }

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, true);
        }

        return $results;
    }

    /**
     * Drop sockets whose response did not finish. Completed sockets remain usable;
     * if every socket failed, establish one clean connection for the retry.
     *
     * @param  list<int>  $socketIndexes
     */
    private function dropIncompleteXoverSockets(array $socketIndexes): void
    {
        foreach ($socketIndexes as $idx) {
            if (! isset($this->sockets[$idx])) {
                continue;
            }

            @fclose($this->sockets[$idx]);
            unset($this->sockets[$idx]);
        }

        $this->sockets = array_values($this->sockets);

        if ($this->sockets === []) {
            $replacement = $this->reconnectOne();

            if ($replacement !== null) {
                $this->sockets[] = $replacement;
            }
        }

        foreach ($this->sockets as $socket) {
            stream_set_blocking($socket, true);
        }
    }

    /**
     * Open connections in parallel using one state machine. SSL connections add
     * an asynchronous TLS handshake between TCP connection and the greeting.
     */
    private function connectAsync(string $host, int|string $port, bool $useSsl): void
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
                $context,
            );

            if ($socket !== false) {
                stream_set_blocking($socket, false);
                $pending[$i] = ['socket' => $socket, 'state' => 'connecting'];
            }
        }

        $ready = [];
        $deadline = microtime(true) + ($useSsl ? 15 : 10);

        while ($pending !== [] && microtime(true) < $deadline) {
            $readSockets = [];
            $writeSockets = [];

            foreach ($pending as $idx => $data) {
                if ($data['state'] === 'connecting') {
                    $writeSockets[$idx] = $data['socket'];
                } elseif ($data['state'] === 'ssl_handshake') {
                    $readSockets[$idx] = $data['socket'];
                    $writeSockets[$idx] = $data['socket'];
                } else {
                    $readSockets[$idx] = $data['socket'];
                }
            }

            $read = $readSockets === [] ? null : array_values($readSockets);
            $write = $writeSockets === [] ? null : array_values($writeSockets);
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 100000);

            if ($changed === false) {
                break;
            }

            if ($changed === 0) {
                continue;
            }

            foreach (array_keys($pending) as $idx) {
                if (! isset($pending[$idx])) {
                    continue;
                }

                $socket = $pending[$idx]['socket'];
                $inRead = $read !== null && \in_array($socket, $read, true);
                $inWrite = $write !== null && \in_array($socket, $write, true);
                $state = $pending[$idx]['state'];

                if ($state === 'connecting') {
                    if ($inWrite) {
                        $pending[$idx]['state'] = $useSsl ? 'ssl_handshake' : 'wait_greeting';
                    }

                    continue;
                }

                if ($state === 'ssl_handshake') {
                    if (! $inRead && ! $inWrite) {
                        continue;
                    }

                    $result = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

                    if ($result === true) {
                        $pending[$idx]['state'] = 'wait_greeting';
                    } elseif ($result === false) {
                        @fclose($socket);
                        unset($pending[$idx]);
                    }

                    continue;
                }

                if (! $inRead) {
                    continue;
                }

                $line = @fgets($socket, 4096);

                if ($line === false || $line === '') {
                    if (feof($socket)) {
                        @fclose($socket);
                        unset($pending[$idx]);
                    }

                    continue;
                }

                $line = trim($line);

                if ($state === 'wait_greeting') {
                    if (! str_starts_with($line, '200') && ! str_starts_with($line, '201')) {
                        @fclose($socket);
                        unset($pending[$idx]);

                        continue;
                    }

                    if ($this->endpoint->username === '' || $this->endpoint->username === '0') {
                        $ready[] = $socket;
                        unset($pending[$idx]);

                        continue;
                    }

                    $this->sendCommand($socket, "AUTHINFO USER {$this->endpoint->username}");
                    $pending[$idx]['state'] = 'wait_user';

                    continue;
                }

                if ($state === 'wait_user') {
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

                    continue;
                }

                if (str_starts_with($line, '281')) {
                    $ready[] = $socket;
                } else {
                    @fclose($socket);
                }

                unset($pending[$idx]);
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
     * Assign queued articles to idle pooled sockets after a slot dies or finishes.
     *
     * @param  array<int|string, int|string>  $pending
     * @param  array<int|string, array<string, mixed>>  $states
     * @param  array<int|string, float>  $deadlines
     * @param  array<int|string, string>  $buffers
     * @param  \SplQueue<int|string>  $queue
     */
    private function redistributeHeadQueue(
        array &$pending,
        array &$states,
        array &$deadlines,
        array &$buffers,
        \SplQueue $queue,
    ): void {
        if ($queue->isEmpty() || $this->quitting) {
            return;
        }

        foreach (array_keys($this->sockets) as $idx) {
            if (isset($pending[$idx]) || $queue->isEmpty()) {
                continue;
            }

            $socket = $this->sockets[$idx];

            if (! is_resource($socket)) {
                continue;
            }

            $this->dispatchNextHead($idx, $socket, $pending, $states, $deadlines, $buffers, $queue);
        }
    }

    /**
     * @param  array<int, int|string>  $pending
     * @param  array<int, array<string, mixed>>  $states
     * @param  array<int, float>  $deadlines
     * @param  array<int, string>  $buffers
     * @param  \SplQueue<int|string>  $queue
     */
    private function dispatchNextHead(
        int $idx,
        mixed $socket,
        array &$pending,
        array &$states,
        array &$deadlines,
        array &$buffers,
        \SplQueue $queue,
    ): bool {
        if ($queue->isEmpty() || $this->quitting) {
            return false;
        }

        $articleNum = $queue->dequeue();
        $pending[$idx] = $articleNum;
        $buffers[$idx] = '';
        $states[$idx] = ['status' => 'wait_response', 'parser' => $this->headerParser->start()];
        $deadlines[$idx] = microtime(true) + $this->headResponseDeadlineSeconds;
        $headId = is_int($articleNum) ? (string) $articleNum : "<$articleNum>";
        $this->sendCommand($socket, "HEAD $headId");

        return true;
    }

    private function headRetryKey(int|string $articleNum): string
    {
        return is_int($articleNum) ? "int:$articleNum" : "string:$articleNum";
    }

    /** Close and remove a pooled socket when its index is known. */
    private function closeSocketAt(int $idx): void
    {
        if (! isset($this->sockets[$idx])) {
            return;
        }

        $socket = $this->sockets[$idx];
        unset($this->sockets[$idx]);

        if (is_resource($socket)) {
            @fclose($socket);
        }
    }

    /**
     * Open a single replacement connection: TCP connect → authenticate → GROUP.
     *
     * Called only after the active HEAD read set drains. The reconnect timeout is
     * deliberately bounded so a provider outage fails the batch instead of turning
     * unattempted articles into failed results.
     *
     * @return resource|null
     */
    private function reconnectOne(): mixed
    {
        $reconnectTimeout = self::RECONNECT_TIMEOUT_SECONDS;
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
        // Read handshake lines byte-by-byte so a server that eagerly sends the
        // first command response cannot have that response stranded in this
        // short-lived protocol instance's private read buffer.
        $protocol = NntpProtocol::forResource($socket, 1);

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
}
