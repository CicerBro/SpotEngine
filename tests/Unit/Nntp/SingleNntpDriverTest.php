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
