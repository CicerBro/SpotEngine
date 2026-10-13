<?php

declare(strict_types=1);

use App\Services\Nntp\HeadBatchOutcome;
use App\Services\Nntp\HeadBatchResult;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\ParallelNntpDriver;

/**
 * @param  list<string|null>  $transcripts
 * @return array{0: ParallelNntpDriver, 1: list<mixed>}
 */
function connectedParallelDriver(array $transcripts, float $headDeadlineSeconds = 1.0, int $connections = 1): array
{
    $servers = [];
    $nextTranscript = 0;
    $driver = new ParallelNntpDriver(
        [
            'host' => 'localhost',
            'port' => 119,
            'ssl' => false,
            'timeout' => 1,
        ],
        $connections,
        connector: function () use (&$servers, &$nextTranscript, $transcripts): mixed {
            $transcript = $transcripts[$nextTranscript] ?? null;
            $nextTranscript++;

            if ($transcript === null) {
                return null;
            }

            [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($server, $transcript);
            $servers[] = $server;

            return $client;
        },
        headResponseDeadlineSeconds: $headDeadlineSeconds,
    );
    $driver->connect(showProgress: false);

    return [$driver, $servers];
}

function closeParallelTestDriver(ParallelNntpDriver $driver, array $servers): void
{
    $driver->detach();

    foreach ($servers as $server) {
        if (is_resource($server)) {
            fclose($server);
        }
    }
}

test('parses wanted Spotnet headers in parallel HEAD responses', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n221 1 <spot@test> headers follow\r\nSubject: ignored\r\nX-XML: <Spotnet>\r\nX-XML: <Title>Parallel</Title>\r\n </Spotnet>\r\n.\r\n",
    ]);

    try {
        expect($driver->headBatch([1], showProgress: false))->toBe([
            1 => ['x-xml' => '<Spotnet><Title>Parallel</Title></Spotnet>'],
        ]);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
});

test('drains overview lines buffered with the status response', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n224 overview follows\r\n1\tBuffered\tPoster\tDate\t<buffered@test>\r\n.\r\n",
    ]);

    try {
        expect($driver->xover(1, 1))->toBe([
            1 => [
                'subject' => 'Buffered',
                'from' => 'Poster',
                'date' => 'Date',
                'message_id' => 'buffered@test',
            ],
        ]);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
});

test('continues after a missing HEAD article and stops when the queue is exhausted', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n430 no such article\r\n",
    ]);
    $results = [];

    try {
        $driver->headBatch(['missing@test', 'available@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use (&$results, $servers): void {
            $results[$id] = $result;

            if ($id === 'missing@test') {
                fwrite($servers[0], "221 2 <available@test> headers follow\r\nX-XML: available\r\n.\r\n");
            }
        });
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($results)->toHaveCount(2)
        ->and($results['missing@test']->outcome)->toBe(HeadBatchOutcome::Missing)
        ->and($results['available@test']->outcome)->toBe(HeadBatchOutcome::Success)
        ->and($results['available@test']->headers)->toBe(['x-xml' => 'available']);
});

test('retries a partially received HEAD once after its absolute deadline', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n221 1 <retried@test> headers follow\r\nX-XML: partial",
        "200 ready\r\n221 1 <retried@test> headers follow\r\nX-XML: retried\r\n.\r\n",
    ], headDeadlineSeconds: 0.01);
    $results = [];

    try {
        $driver->headBatch(['retried@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use (&$results): void {
            $results[$id] = $result;
        });
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($results)->toHaveCount(1)
        ->and($results['retried@test']->outcome)->toBe(HeadBatchOutcome::Success)
        ->and($results['retried@test']->headers)->toBe(['x-xml' => 'retried']);
});

test('aborts without completing the article when its fresh reconnect fails', function () {
    [$driver, $servers] = connectedParallelDriver(["200 ready\r\n"], headDeadlineSeconds: 0.01);
    $callbacks = [];

    try {
        $driver->headBatch(['untried@test'], showProgress: false, onArticle: function (int|string $id) use (&$callbacks): void {
            $callbacks[] = $id;
        });
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($callbacks)->toBe([]);
})->throws(NntpException::class, 'Failed to reconnect');

test('returns an exhausted timeout outcome only after the fresh retry', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n",
        "200 ready\r\n",
    ], headDeadlineSeconds: 0.01);
    $results = [];

    try {
        $driver->headBatch(['timed-out@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use (&$results): void {
            $results[$id] = $result;
        });
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($results)->toHaveCount(1)
        ->and($results['timed-out@test']->outcome)->toBe(HeadBatchOutcome::TimedOutAfterRetry);
});

test('aborts streaming callbacks on systemic HEAD failures', function () {
    [$driver, $servers] = connectedParallelDriver(["200 ready\r\n501 command syntax error\r\n"]);
    $callbacks = [];

    try {
        $driver->headBatch(['first@test', 'second@test'], showProgress: false, onArticle: function (int|string $id) use (&$callbacks): void {
            $callbacks[] = $id;
        });
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($callbacks)->toBe([]);
})->throws(NntpException::class, 'HEAD failed');

test('invokes a callback at most once for an article across a retry', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n",
        "200 ready\r\n221 1 <once@test> headers follow\r\nX-XML: one\r\n.\r\n",
    ], headDeadlineSeconds: 0.01);
    $callbacks = [];

    try {
        $driver->headBatch(['once@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use (&$callbacks): void {
            $callbacks[] = [$id, $result->outcome];
        });
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($callbacks)->toBe([['once@test', HeadBatchOutcome::Success]]);
});

test('does not invoke callbacks for pending articles after an interrupt', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n221 1 <first@test> headers follow\r\nX-XML: one\r\n.\r\n",
    ]);
    $callbacks = [];

    try {
        $driver->headBatch(['first@test', 'second@test', 'third@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use (&$callbacks, $driver): void {
            $callbacks[] = [$id, $result->outcome];
            $driver->quit();
        });
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($callbacks)->toBe([['first@test', HeadBatchOutcome::Success]]);
});

test('drops stale group backends and keeps their common range', function () {
    $servers = [];
    $nextLastArticle = 0;
    $lastArticles = [1000, 950, 700];
    $driver = new ParallelNntpDriver(
        ['host' => 'localhost', 'port' => 119, 'ssl' => false, 'timeout' => 1],
        3,
        connector: function () use (&$servers, &$nextLastArticle, $lastArticles): mixed {
            [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $last = $lastArticles[$nextLastArticle++];
            fwrite($server, "200 ready\r\n211 900 1 {$last} free.pt\r\n");
            $servers[] = $server;

            return $client;
        },
    );
    $driver->connect(showProgress: false);

    try {
        $summary = $driver->group('free.pt');
        $connectionCount = $driver->getConnectionCount();
    } finally {
        closeParallelTestDriver($driver, $servers);
    }

    expect($summary['last'])->toBe(950)
        ->and($connectionCount)->toBe(2);
});

test('rejects oversized parallel HEAD responses without emitting article callbacks', function (string $limit, int $maximum, string $headers) {
    config()->set('spotengine.payload_limits.' . $limit, $maximum);
    [$driver, $servers] = connectedParallelDriver(["200 ready\r\n221 headers\r\n" . $headers]);
    $callbacks = [];

    try {
        expect(fn () => $driver->headBatch([1], showProgress: false, onArticle: function (int|string $id) use (&$callbacks): void {
            $callbacks[] = $id;
        }))->toThrow(NntpException::class, 'payload limit');
        expect($callbacks)->toBe([]);
        expect($driver->getConnectionCount())->toBe(0);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
})->with([
    'bytes' => ['nntp_header_bytes', 30, "X-XML: larger than allowed\r\n.\r\n"],
    'line' => ['nntp_line_bytes', 20, str_repeat('x', 21)],
    'line count' => ['nntp_response_lines', 2, "X-XML: a\r\nX-XML: b\r\n.\r\n"],
]);

test('rejects oversized parallel overview responses and discards the connection', function (string $limit, int $maximum, string $overview) {
    config()->set('spotengine.payload_limits.' . $limit, $maximum);
    [$driver, $servers] = connectedParallelDriver(["200 ready\r\n224 follows\r\n" . $overview]);

    try {
        expect(fn () => $driver->xover(1, 1))->toThrow(NntpException::class, 'payload limit');
        expect($driver->getConnectionCount())->toBe(0);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
})->with([
    'bytes' => ['nntp_response_bytes', 30, "1\tSubject\tPoster\tDate\t<test@test>\r\n.\r\n"],
    'line' => ['nntp_line_bytes', 20, str_repeat('x', 21)],
    'line count' => ['nntp_response_lines', 2, "\r\n\r\n.\r\n"],
]);

test('reports failed reconnects after oversized greetings close their sockets', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n",
        str_repeat('x', 4097),
    ], headDeadlineSeconds: 0.01);

    try {
        expect(fn () => $driver->headBatch([1], showProgress: false))
            ->toThrow(NntpException::class, 'Failed to reconnect');
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
});

test('aborts legacy HEAD batches on fatal server responses and closes unread sockets', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n400 service unavailable\r\n",
        "200 ready\r\n221 headers follow\r\nX-XML: pending",
    ], connections: 2);

    try {
        expect(fn () => $driver->headBatch(['failed@test', 'pending@test'], showProgress: false))
            ->toThrow(NntpException::class, 'HEAD failed: 400');
        expect($driver->getConnectionCount())->toBe(0);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
});

test('returns numeric missing articles consistently in both HEAD APIs', function (bool $useCallback) {
    [$driver, $servers] = connectedParallelDriver(["200 ready\r\n423 no such article number\r\n"]);
    $results = [];

    try {
        $legacyResults = $driver->headBatch([123], showProgress: false, onArticle: $useCallback
            ? function (int|string $id, HeadBatchResult $result) use (&$results): void {
                $results[$id] = $result->outcome;
            }
            : null);

        expect($legacyResults)->toBe($useCallback ? [] : [123 => null]);
        expect($results)->toBe($useCallback ? [123 => HeadBatchOutcome::Missing] : []);
        expect($driver->getConnectionCount())->toBe(1);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
})->with([false, true]);

test('preserves outstanding HEAD network deadlines while an article callback blocks', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n221 headers follow\r\nX-XML: first\r\n.\r\n",
        "200 ready\r\n",
    ], headDeadlineSeconds: 0.01, connections: 2);
    $results = [];

    try {
        $driver->headBatch(['first@test', 'second@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use (&$results, $servers): void {
            $results[$id] = $result->headers;

            if ($id === 'first@test') {
                fwrite($servers[1], "221 headers follow\r\nX-XML: second\r\n.\r\n");
                usleep(40_000);
            }
        });

        expect($results)->toBe([
            'first@test' => ['x-xml' => 'first'],
            'second@test' => ['x-xml' => 'second'],
        ]);
        expect($driver->getConnectionCount())->toBe(2);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
});

test('rejects numeric missing status for a message ID request', function () {
    [$driver, $servers] = connectedParallelDriver(["200 ready\r\n423 no such article number\r\n"]);

    try {
        expect(fn () => $driver->headBatch(['message@test'], showProgress: false))
            ->toThrow(NntpException::class, 'HEAD failed: 423');
        expect($driver->getConnectionCount())->toBe(0);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
});

test('closes pending HEAD connections when an article callback throws', function () {
    [$driver, $servers] = connectedParallelDriver([
        "200 ready\r\n221 headers follow\r\nX-XML: first\r\n.\r\n",
        "200 ready\r\n221 headers follow\r\nX-XML: pending",
    ], connections: 2);

    try {
        expect(fn () => $driver->headBatch(['first@test', 'pending@test'], showProgress: false, onArticle: function (): void {
            throw new RuntimeException('callback failed');
        }))->toThrow(RuntimeException::class, 'callback failed');
        expect($driver->getConnectionCount())->toBe(0);
    } finally {
        closeParallelTestDriver($driver, $servers);
    }
});

test('frames fragmented asynchronous greeting and authentication replies within bounds', function (bool $authenticate, ?string $oversizedStage) {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the NNTP handshake test server.');
    }

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    $address = stream_socket_get_name($listener, false);
    $port = (int) substr(strrchr($address, ':'), 1);
    $pid = pcntl_fork();

    if ($pid === -1) {
        fclose($listener);

        throw new RuntimeException('Cannot start the NNTP test server');
    }

    if ($pid === 0) {
        $socket = stream_socket_accept($listener, 2);

        if ($socket === false) {
            exit(1);
        }

        stream_set_timeout($socket, 2);
        $respond = function (string $prefix, string $suffix, string $stage) use ($socket, $listener, $oversizedStage): void {
            fwrite($socket, $prefix);
            usleep(20_000);
            fwrite($socket, $oversizedStage === $stage ? str_repeat('x', 4096) : $suffix);

            if ($oversizedStage === $stage) {
                fclose($socket);
                fclose($listener);
                exit(0);
            }
        };
        $respond('20', "0 ready\r\n", 'greeting');

        if ($authenticate) {
            if (fgets($socket) !== "AUTHINFO USER user\r\n") {
                exit(2);
            }

            $respond('38', "1 password required\r\n", 'user');

            if (fgets($socket) !== "AUTHINFO PASS pass\r\n") {
                exit(3);
            }

            $respond('28', "1 authenticated\r\n", 'pass');
        }

        fclose($socket);
        fclose($listener);
        exit(0);
    }

    fclose($listener);
    $driver = new ParallelNntpDriver([
        'host' => '127.0.0.1',
        'port' => $port,
        'ssl' => false,
        'timeout' => 1,
        'username' => $authenticate ? 'user' : '',
        'password' => $authenticate ? 'pass' : '',
    ], 1);

    try {
        if ($oversizedStage === null) {
            $driver->connect(showProgress: false);

            expect($driver->getConnectionCount())->toBe(1);
        } else {
            expect(fn () => $driver->connect(showProgress: false))
                ->toThrow(NntpException::class, 'Failed to establish any NNTP connections');
            expect($driver->getConnectionCount())->toBe(0);
        }
    } finally {
        $driver->detach();
        pcntl_waitpid($pid, $status);
    }

    expect(pcntl_wexitstatus($status))->toBe(0);
})->with([
    'greeting' => [false, null],
    'authentication' => [true, null],
    'oversized greeting' => [false, 'greeting'],
    'oversized username reply' => [true, 'user'],
    'oversized password reply' => [true, 'pass'],
]);
