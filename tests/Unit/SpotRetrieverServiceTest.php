<?php

declare(strict_types=1);

use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use App\Services\SpotMutationService;
use App\Services\SpotRetrieverService;

test('buildBatches with forwardNewToOld true returns batches newest first', function () {
    $service = new SpotRetrieverService(new SpotParser, new NntpService(config('spotengine.nntp')), new SigningService, app(SpotMutationService::class));
    $method = new ReflectionMethod($service, 'buildBatches');
    $method->setAccessible(true);

    $batches = $method->invoke($service, 1, 100, 30, false, true);

    expect($batches)->toHaveCount(4);
    expect($batches[0])->toBe([71, 100]);
    expect($batches[1])->toBe([41, 70]);
    expect($batches[2])->toBe([11, 40]);
    expect($batches[3])->toBe([1, 10]);
});

test('buildBatches with forwardNewToOld false returns batches oldest first', function () {
    $service = new SpotRetrieverService(new SpotParser, new NntpService(config('spotengine.nntp')), new SigningService, app(SpotMutationService::class));
    $method = new ReflectionMethod($service, 'buildBatches');
    $method->setAccessible(true);

    $batches = $method->invoke($service, 1, 100, 30, false, false);

    expect($batches)->toHaveCount(4);
    expect($batches[0])->toBe([1, 30]);
    expect($batches[1])->toBe([31, 60]);
    expect($batches[2])->toBe([61, 90]);
    expect($batches[3])->toBe([91, 100]);
});

test('deduplicateSpotsByMessageId keeps only last message id occurrence and drops missing ids', function () {
    $service = new SpotRetrieverService(new SpotParser, new NntpService(config('spotengine.nntp')), new SigningService, app(SpotMutationService::class));
    $method = new ReflectionMethod($service, 'deduplicateSpotsByMessageId');
    $method->setAccessible(true);

    $input = [
        ['message_id' => 'a', 'title' => 'first-a'],
        ['message_id' => 'b', 'title' => 'first-b'],
        ['message_id' => 'a', 'title' => 'last-a'],
        ['message_id' => '', 'title' => 'empty-id'],
        ['title' => 'missing-id'],
        ['message_id' => 'c', 'title' => 'only-c'],
        ['message_id' => 'b', 'title' => 'last-b'],
    ];

    /** @var array<int, array{message_id: string, title: string}> $deduplicated */
    $deduplicated = $method->invoke($service, $input);

    expect(array_values($deduplicated))->toBe([
        ['message_id' => 'a', 'title' => 'last-a'],
        ['message_id' => 'c', 'title' => 'only-c'],
        ['message_id' => 'b', 'title' => 'last-b'],
    ]);
});
