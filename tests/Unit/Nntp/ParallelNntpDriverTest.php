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
function connectedParallelDriver(array $transcripts, float $headDeadlineSeconds = 1.0): array
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
        1,
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
