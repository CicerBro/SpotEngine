<?php

declare(strict_types=1);

use App\Services\Nntp\HeadBatchOutcome;
use App\Services\Nntp\HeadBatchResult;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\SingleNntpDriver;

test('streams typed HEAD outcomes from the single driver', function () {
    $driver = Mockery::mock(SingleNntpDriver::class, ['localhost', 119, false])->makePartial();
    $driver->shouldReceive('head')
        ->with('available@test')
        ->once()
        ->andReturn(['x-xml' => 'available']);
    $driver->shouldReceive('head')
        ->with('missing@test')
        ->once()
        ->andThrow(new NntpException('No such article', responseCode: 430, operation: 'HEAD'));
    $results = [];

    $driver->headBatch(['available@test', 'missing@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use (&$results): void {
        $results[$id] = $result;
    });

    expect($results['available@test']->outcome)->toBe(HeadBatchOutcome::Success)
        ->and($results['available@test']->headers)->toBe(['x-xml' => 'available'])
        ->and($results['missing@test']->outcome)->toBe(HeadBatchOutcome::Missing);
});

test('propagates fatal single-driver retrieval failures without a callback', function () {
    $driver = Mockery::mock(SingleNntpDriver::class, ['localhost', 119, false])->makePartial();
    $driver->shouldReceive('head')
        ->with('unavailable@test')
        ->once()
        ->andThrow(new NntpException('Service unavailable', responseCode: 400, operation: 'HEAD'));

    expect(fn () => $driver->headBatch(['unavailable@test'], showProgress: false))
        ->toThrow(NntpException::class, 'Service unavailable');
});

test('reports header progress during fetching and preserves results', function (bool $showProgress) {
    $driver = Mockery::mock(SingleNntpDriver::class, ['localhost', 119, false])->makePartial();
    $driver->shouldReceive('head')->times(51)->andReturnUsing(function (int $id) use ($showProgress): array {
        if ($showProgress && $id === 1) {
            expect(ob_get_contents())->toContain('Progress: 0/51 (0%)');
        }

        if ($showProgress && $id === 51) {
            expect(ob_get_contents())->toContain('Progress: 50/51 (98%)');
        }

        if ($id === 25) {
            throw new NntpException('No such article', responseCode: 430, operation: 'HEAD');
        }

        return ['subject' => 'Available'];
    });
    ob_start();

    try {
        $results = $driver->headBatch(range(1, 51), showProgress: $showProgress);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($results)->toHaveCount(51)
        ->and($results[25])->toBeNull()
        ->and($results[51])->toBe(['subject' => 'Available']);

    if ($showProgress) {
        expect($output)->toContain('Progress: 51/51 (100%)')->toEndWith("\n");
    } else {
        expect($output)->toBe('');
    }
})->with([true, false]);

test('counts missing streamed articles in header progress', function () {
    $driver = Mockery::mock(SingleNntpDriver::class, ['localhost', 119, false])->makePartial();
    $driver->shouldReceive('head')->with(1)->once()
        ->andThrow(new NntpException('No such article', responseCode: 430, operation: 'HEAD'));
    $outcomes = [];
    ob_start();

    try {
        $results = $driver->headBatch([1], onArticle: function (int|string $id, HeadBatchResult $result) use (&$outcomes): void {
            $outcomes[$id] = $result->outcome;
        });
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($outcomes)->toBe([1 => HeadBatchOutcome::Missing]);
    expect($results)->toBe([]);
    expect($output)->toContain('Progress: 1/1 (100%)');
});

test('produces no header progress when there are no articles', function () {
    $driver = new SingleNntpDriver('localhost', 119, false);
    ob_start();

    try {
        $results = $driver->headBatch([]);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($results)->toBe([]);
    expect($output)->toBe('');
});

/** @return array{SingleNntpDriver, resource} */
function singleDriverTranscript(string $transcript, bool $compression = false): array
{
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, $transcript);
    $driver = new SingleNntpDriver('localhost', 119, false);
    (new ReflectionProperty($driver, 'socket'))->setValue($driver, $client);
    (new ReflectionProperty($driver, 'compressionEnabled'))->setValue($driver, $compression);

    return [$driver, $server];
}

test('continues after definitively missing articles in both single-driver batch APIs', function (int|string $id, string $status, bool $streaming) {
    [$driver, $server] = singleDriverTranscript($status . "\r\n221 headers\r\nSubject: Available\r\n.\r\n");
    $outcomes = [];
    $callback = $streaming ? function (int|string $articleId, HeadBatchResult $result) use (&$outcomes): void {
        $outcomes[$articleId] = $result;
    } : null;

    try {
        $results = $driver->headBatch([$id, 'available@test'], showProgress: false, onArticle: $callback);

        expect($driver->isConnected())->toBeTrue();

        if ($streaming) {
            expect($results)->toBe([]);
            expect($outcomes[$id]->outcome)->toBe(HeadBatchOutcome::Missing);
            expect($outcomes['available@test']->headers)->toBe(['subject' => 'Available']);
        } else {
            expect($results)->toBe([$id => null, 'available@test' => ['subject' => 'Available']]);
        }
    } finally {
        $driver->detach();
        fclose($server);
    }
})->with([
    'message ID' => ['missing@test', '430 no such article'],
    'article number' => [42, '423 no such article number'],
])->with([true, false]);

test('closes single-driver batches on fatal replies without completing pending articles', function (string $status, bool $streaming) {
    [$driver, $server] = singleDriverTranscript($status . "\r\n");
    $outcomes = [];
    $callback = $streaming ? function (int|string $id, HeadBatchResult $result) use (&$outcomes): void {
        $outcomes[$id] = $result;
    } : null;

    try {
        expect(fn () => $driver->headBatch(['failed@test', 'pending@test'], showProgress: false, onArticle: $callback))
            ->toThrow(NntpException::class);

        expect($driver->isConnected())->toBeFalse();
        expect($outcomes)->toBe([]);
        expect(stream_get_contents($server))->toBe("HEAD <failed@test>\r\n");
    } finally {
        $driver->detach();
        fclose($server);
    }
})->with([
    'service failure' => ['400 service discontinued'],
    'authentication failure' => ['480 authentication required'],
    'unsupported command' => ['500 unknown command'],
    'wrong missing response for a message ID' => ['423 no such article number'],
    'malformed status' => ['malformed'],
])->with([true, false]);

test('propagates incomplete single-driver batch responses and detaches the connection', function (string $transcript, bool $streaming) {
    [$driver, $server] = singleDriverTranscript($transcript);
    stream_socket_shutdown($server, STREAM_SHUT_WR);
    $outcomes = [];
    $callback = $streaming ? function (int|string $id, HeadBatchResult $result) use (&$outcomes): void {
        $outcomes[$id] = $result;
    } : null;

    try {
        expect(fn () => $driver->headBatch(['incomplete@test'], showProgress: false, onArticle: $callback))
            ->toThrow(NntpException::class, 'connection closed');

        expect($driver->isConnected())->toBeFalse();
        expect($outcomes)->toBe([]);
    } finally {
        $driver->detach();
        fclose($server);
    }
})->with([
    'status' => [''],
    'headers' => ["221 headers\r\nSubject: Incomplete\r\n"],
])->with([true, false]);

test('propagates single-driver batch timeouts and detaches the connection', function (bool $streaming) {
    $driver = Mockery::mock(SingleNntpDriver::class, ['localhost', 119, false])->makePartial();
    $socket = fopen('php://temp', 'r+');
    (new ReflectionProperty(SingleNntpDriver::class, 'socket'))->setValue($driver, $socket);
    $driver->shouldReceive('head')->with('timeout@test')->once()
        ->andThrow(new NntpException('HEAD timed out', timedOut: true));
    $outcomes = [];
    $callback = $streaming ? function (int|string $id, HeadBatchResult $result) use (&$outcomes): void {
        $outcomes[$id] = $result;
    } : null;

    expect(fn () => $driver->headBatch(['timeout@test'], showProgress: false, onArticle: $callback))
        ->toThrow(NntpException::class, 'HEAD timed out');

    expect($driver->isConnected())->toBeFalse();
    expect(is_resource($socket))->toBeFalse();
    expect($outcomes)->toBe([]);
})->with([true, false]);

test('propagates callback errors and closes the single-driver batch connection', function (string $transcript) {
    [$driver, $server] = singleDriverTranscript($transcript);
    $callbackError = new NntpException('Callback failed', responseCode: 430);

    try {
        expect(fn () => $driver->headBatch(['first@test', 'pending@test'], showProgress: false, onArticle: function () use ($callbackError): void {
            throw $callbackError;
        }))->toThrow($callbackError);

        expect($driver->isConnected())->toBeFalse();
        expect(stream_get_contents($server))->toBe("HEAD <first@test>\r\n");
    } finally {
        $driver->detach();
        fclose($server);
    }
})->with([
    'successful HEAD' => ["221 headers\r\nSubject: Available\r\n.\r\n"],
    'missing HEAD' => ["430 no such article\r\n"],
]);

test('propagates oversized single-driver batch headers in both APIs', function (bool $streaming) {
    config()->set('spotengine.payload_limits.nntp_header_bytes', 16);
    [$driver, $server] = singleDriverTranscript("221 headers\r\n" . str_repeat('x', 32) . "\r\n.\r\n");
    $outcomes = [];
    $callback = $streaming ? function (int|string $id, HeadBatchResult $result) use (&$outcomes): void {
        $outcomes[$id] = $result;
    } : null;

    try {
        expect(fn () => $driver->headBatch(['oversized@test'], showProgress: false, onArticle: $callback))
            ->toThrow(NntpException::class, 'payload limit');

        expect($driver->isConnected())->toBeFalse();
        expect($outcomes)->toBe([]);
        $driver->quit();
    } finally {
        $driver->detach();
        fclose($server);
    }
})->with([true, false]);

test('bounds HEAD ARTICLE and BODY reads and allows cleanup after rejecting payloads', function (string $method, string $limit, string $status) {
    config()->set('spotengine.payload_limits.' . $limit, 16);
    [$driver, $server] = singleDriverTranscript($status . "\r\n" . str_repeat('x', 32) . "\r\n.\r\n");

    try {
        expect(fn () => $driver->{$method}('test@test'))->toThrow(NntpException::class, 'payload limit');
        expect($driver->isConnected())->toBeFalse();
        $driver->quit();
        expect($driver->isConnected())->toBeFalse();
    } finally {
        $driver->detach();
        fclose($server);
    }
})->with([
    'HEAD' => ['head', 'nntp_header_bytes', '221 headers'],
    'ARTICLE' => ['article', 'nntp_article_bytes', '220 article'],
    'BODY' => ['body', 'nntp_body_bytes', '222 body'],
]);

test('preserves compressed HEAD response support', function () {
    [$driver, $server] = singleDriverTranscript(
        "221 COMPRESS=GZIP\r\n" . gzcompress("X-XML: compressed\r\n") . ".\r\n",
        compression: true,
    );

    try {
        expect($driver->head('test@test'))->toBe(['x-xml' => 'compressed']);
    } finally {
        $driver->detach();
        fclose($server);
    }
});

test('bounds decompressed HEAD output', function () {
    config()->set('spotengine.payload_limits.nntp_header_bytes', 128);
    [$driver, $server] = singleDriverTranscript(
        "221 COMPRESS=GZIP\r\n" . gzcompress('X-XML: ' . str_repeat('x', 4096)) . ".\r\n",
        compression: true,
    );
    stream_socket_shutdown($server, STREAM_SHUT_WR);

    try {
        expect(fn () => $driver->head('test@test'))->toThrow(NntpException::class, 'decompress');
    } finally {
        $driver->detach();
        fclose($server);
    }
});

test('cleans up safely when an oversized QUIT response closes the socket', function () {
    [$driver, $server] = singleDriverTranscript(str_repeat('x', 4097));

    try {
        $driver->quit();

        expect($driver->isConnected())->toBeFalse();
    } finally {
        $driver->detach();
        fclose($server);
    }
});
