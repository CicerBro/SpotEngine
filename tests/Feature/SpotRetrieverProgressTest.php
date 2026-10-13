<?php

declare(strict_types=1);

use App\Services\AsyncSpotRetrieverService;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\SpotRetrieverService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('announces each fetching phase before network requests and enables header progress', function (string $serviceClass) {
    $driver = Mockery::mock(NntpDriverInterface::class);
    $driver->shouldReceive('xover')->with(10, 11)->once()->andReturnUsing(function (): array {
        expect(ob_get_contents())->toContain('Fetching overview for articles 10-11...');

        return [10 => [
            'from' => 'Poster <abc123@17a11b03c04d44z02.110917840.20.1771524587.1.NL.Sig123>',
            'subject' => 'Spot title',
            'date' => 'Thu, 19 Feb 2026 12:00:00 +0000',
            'message_id' => 'progress@example.test',
        ]];
    });
    $driver->shouldReceive('headBatch')->with(['progress@example.test'], true)->once()->andReturnUsing(function (): array {
        expect(ob_get_contents())->toContain('Fetching headers for 1 spots...');

        return ['progress@example.test' => []];
    });
    $service = app($serviceClass);
    (new ReflectionProperty(SpotRetrieverService::class, 'nntp'))->setValue($service, $driver);
    $fetchBatch = new ReflectionMethod($service, 'fetchBatch');
    ob_start();

    try {
        [$processed, $spots, $moderationCommands, $lastArticle] = $fetchBatch->invoke($service, 10, 11);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    expect($output)->toBe("Fetching overview for articles 10-11...\nFetching headers for 1 spots...\n");
    expect($processed)->toBe(1);
    expect($spots)->toHaveCount(1)
        ->and($spots[0]['message_id'])->toBe('progress@example.test');
    expect($moderationCommands)->toBe([]);
    expect($lastArticle)->toBe(11);
})->with([SpotRetrieverService::class, AsyncSpotRetrieverService::class]);

test('enriches spots by overview message ID while retaining article checkpoints and missing headers', function (string $serviceClass) {
    $overview = [];

    foreach ([101 => 'first@example.test', 104 => 'missing@example.test', 109 => 'other@example.test', 110 => 'first@example.test'] as $articleNumber => $messageId) {
        $overview[$articleNumber] = [
            'from' => 'Poster <abc123@17a11b03c04d44z02.110917840.20.1771524587.1.NL.Sig123>',
            'subject' => 'Overview title',
            'date' => 'Thu, 19 Feb 2026 12:00:00 +0000',
            'message_id' => $messageId,
        ];
    }

    $driver = Mockery::mock(NntpDriverInterface::class);
    $driver->shouldReceive('xover')->with(101, 110)->once()->andReturn($overview);
    $driver->shouldReceive('headBatch')->with([
        'first@example.test', 'missing@example.test', 'other@example.test', 'first@example.test',
    ], true)->once()->andReturn([
        'other@example.test' => ['x-xml' => '<Spotnet><Posting><Title>Other</Title><Description>Other details</Description></Posting></Spotnet>'],
        'missing@example.test' => null,
        'first@example.test' => ['x-xml' => '<Spotnet><Posting><Title>First</Title><Description>First details</Description></Posting></Spotnet>'],
    ]);
    $service = app($serviceClass);
    (new ReflectionProperty(SpotRetrieverService::class, 'nntp'))->setValue($service, $driver);
    $fetchBatch = new ReflectionMethod($service, 'fetchBatch');
    ob_start();

    try {
        [$processed, $spots, $moderationCommands, $lastArticle] = $fetchBatch->invoke($service, 101, 110);
    } finally {
        ob_end_clean();
    }

    expect($processed)->toBe(4);
    expect(array_column($spots, 'message_id'))->toBe([
        'first@example.test', 'missing@example.test', 'other@example.test', 'first@example.test',
    ]);
    expect(array_column($spots, 'description'))->toBe(['First details', null, 'Other details', 'First details']);
    expect(array_column($spots, 'title'))->toBe(array_fill(0, 4, 'Overview title'));
    expect($moderationCommands)->toBe([]);
    expect($lastArticle)->toBe(110);
})->with([SpotRetrieverService::class, AsyncSpotRetrieverService::class]);
