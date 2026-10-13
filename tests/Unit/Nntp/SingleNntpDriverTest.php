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

test('preserves legacy null results for single-driver retrieval failures', function () {
    $driver = Mockery::mock(SingleNntpDriver::class, ['localhost', 119, false])->makePartial();
    $driver->shouldReceive('head')
        ->with('unavailable@test')
        ->once()
        ->andThrow(new NntpException('Service unavailable', responseCode: 400, operation: 'HEAD'));

    expect($driver->headBatch(['unavailable@test'], showProgress: false))->toBe([
        'unavailable@test' => null,
    ]);
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
