<?php

declare(strict_types=1);

use App\Models\UsenetState;
use App\Services\AsyncSpotRetrieverService;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use App\Services\SpotBanService;
use App\Services\SpotMutationService;
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

test('selects the retrieval driver while preserving defaults without an override', function (string $configuredDriver, ?string $overrideDriver, string $expectedDriver) {
    $state = UsenetState::query()->create([
        'newsgroup' => 'pipeline.test',
        'last_article_id' => 100,
        'first_article_id' => 1,
        'last_backfilled_article_id' => 0,
    ]);
    $config = ['host' => 'localhost', 'driver' => $configuredDriver, 'groups' => ['spots' => 'pipeline.test'], 'connections' => 3];
    $driver = Mockery::mock(NntpDriverInterface::class);
    $driver->shouldReceive('connect')->once();
    $driver->shouldReceive('group')->with('pipeline.test')->once()->andReturn(['count' => 1, 'first' => 101, 'last' => 101, 'group' => 'pipeline.test']);
    $driver->shouldReceive('xover')->with(101, 101)->once()->andReturn([]);
    $driver->shouldReceive('quit')->once();
    $nntpService = Mockery::mock(NntpService::class, [$config]);
    $nntpService->shouldReceive('getConfig')->once()->andReturn($config);
    $nntpService->shouldReceive('makeDriver')->with($overrideDriver !== null ? 3 : null, $expectedDriver)->once()->andReturn($driver);
    $service = new SpotRetrieverService(new SpotParser, $nntpService, new SigningService, app(SpotMutationService::class), app(SpotBanService::class));
    ob_start();

    try {
        $result = $service->retrieve(limit: 1, driver: $overrideDriver);
    } finally {
        ob_end_clean();
    }

    expect($result)->toBe(['processed' => 0, 'inserted' => 0, 'last_article' => 101]);
    expect($state->fresh()->last_article_id)->toBe(101);
})->with([
    ['pipelined', null, 'pipelined'],
    ['single', null, 'single'],
    ['parallel', null, 'single'],
    ['parallel', 'pipelined', 'pipelined'],
    ['pipelined', 'parallel', 'parallel'],
    ['parallel', 'single', 'single'],
]);

test('uses the configured driver for backfill unless the run overrides it', function (string $configuredDriver, ?string $overrideDriver) {
    $state = UsenetState::query()->create([
        'newsgroup' => 'backfill.test',
        'last_article_id' => 100,
        'first_article_id' => 1,
        'last_backfilled_article_id' => 0,
    ]);
    $config = ['host' => 'localhost', 'driver' => $configuredDriver, 'groups' => ['spots' => 'backfill.test'], 'connections' => 3];
    $driver = Mockery::mock(NntpDriverInterface::class);
    $driver->shouldReceive('connect')->once();
    $driver->shouldReceive('group')->with('backfill.test')->once()->andReturn(['count' => 101, 'first' => 1, 'last' => 101, 'group' => 'backfill.test']);
    $driver->shouldReceive('xover')->with(99, 99)->once()->andReturn([]);
    $driver->shouldReceive('quit')->once();
    $nntpService = Mockery::mock(NntpService::class, [$config]);
    $nntpService->shouldReceive('getConfig')->once()->andReturn($config);
    $nntpService->shouldReceive('makeDriver')->withArgs(
        fn (int $connections, ?string $selectedDriver = null): bool => $connections === 5 && $selectedDriver === $overrideDriver,
    )->once()->andReturn($driver);
    $service = new SpotRetrieverService(new SpotParser, $nntpService, new SigningService, app(SpotMutationService::class), app(SpotBanService::class));
    ob_start();

    try {
        $result = $service->retrieve(backfill: true, limit: 1, connections: 5, driver: $overrideDriver);
    } finally {
        ob_end_clean();
    }

    expect($result)->toBe(['processed' => 0, 'inserted' => 0, 'last_article' => 99]);
    expect($state->fresh()->last_article_id)->toBe(100);
    expect($state->fresh()->last_backfilled_article_id)->toBe(99);
})->with([
    ['parallel', null],
    ['single', null],
    ['pipelined', null],
    ['parallel', 'pipelined'],
    ['pipelined', 'parallel'],
    ['parallel', 'single'],
]);

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

test('failed HEAD retrieval leaves the checkpoint and stored spots untouched', function (string $serviceClass) {
    $state = UsenetState::query()->create([
        'newsgroup' => 'failure.test',
        'last_article_id' => 100,
        'first_article_id' => 1,
        'last_backfilled_article_id' => 0,
    ]);
    $config = ['host' => 'localhost', 'driver' => 'parallel', 'groups' => ['spots' => 'failure.test'], 'connections' => 3];
    $driver = Mockery::mock(NntpDriverInterface::class);
    $driver->shouldReceive('connect')->once();
    $driver->shouldReceive('group')->with('failure.test')->once()->andReturn(['count' => 101, 'first' => 1, 'last' => 101, 'group' => 'failure.test']);
    $driver->shouldReceive('xover')->with(101, 101)->once()->andReturn([101 => [
        'from' => 'Poster <abc123@17a11b03c04d44z02.110917840.20.1771524587.1.NL.Sig123>',
        'subject' => 'Spot title',
        'date' => 'Thu, 19 Feb 2026 12:00:00 +0000',
        'message_id' => 'failure@example.test',
    ]]);
    $driver->shouldReceive('headBatch')->with(['failure@example.test'], true)->once()
        ->andThrow(new NntpException('HEAD service failed', responseCode: 400, operation: 'HEAD'));
    $driver->shouldReceive('quit')->once();
    $nntpService = Mockery::mock(NntpService::class, [$config]);
    $nntpService->shouldReceive('getConfig')->once()->andReturn($config);
    $nntpService->shouldReceive('makeDriver')->with(3, 'single')->once()->andReturn($driver);
    $service = new $serviceClass(new SpotParser, $nntpService, new SigningService, app(SpotMutationService::class), app(SpotBanService::class));
    ob_start();

    try {
        expect(fn () => $service->retrieve(limit: 1, driver: 'single'))
            ->toThrow(NntpException::class, 'HEAD service failed');
    } finally {
        ob_end_clean();
    }

    expect($state->fresh()->last_article_id)->toBe(100);
    $this->assertDatabaseCount('spots', 0);
})->with([SpotRetrieverService::class, AsyncSpotRetrieverService::class]);
