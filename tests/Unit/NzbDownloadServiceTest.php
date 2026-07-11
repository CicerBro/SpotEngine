<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\NzbDownloadService;
use App\Services\SpotEnricher;

test('NZB download falls back to the configured alternate NNTP provider', function () {
    $spot = new Spot([
        'title' => 'Fallback NZB',
        'nzb_segments' => ['segment@test'],
    ]);
    $spot->setAttribute('id', 987654321);

    $primary = Mockery::mock(SingleNntpDriver::class);
    $primary->shouldReceive('connect')
        ->once()
        ->andThrow(new NntpException('Primary provider unavailable'));
    $primary->shouldReceive('quit')->once();

    $alternate = Mockery::mock(SingleNntpDriver::class);
    $alternate->shouldReceive('connect')->once();
    $alternate->shouldReceive('group')
        ->once()
        ->with('alt.binaries.ftd')
        ->andReturn(['count' => 1, 'first' => 1, 'last' => 1, 'group' => 'alt.binaries.ftd']);
    $alternate->shouldReceive('body')
        ->once()
        ->with('segment@test')
        ->andReturn('<?xml version="1.0"?><nzb><file subject="fallback"/></nzb>');
    $alternate->shouldReceive('quit')->once();

    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('getConfig')->once()->andReturn([
        'groups' => ['spots' => 'free.pt', 'nzb' => 'alt.binaries.ftd'],
    ]);
    $nntpService->shouldReceive('makeDriver')->once()->with(null, 'single')->andReturn($primary);
    $nntpService->shouldReceive('makeAlternateDriver')->once()->andReturn($alternate);

    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);

    $cacheDirectory = storage_path('framework/testing/nzb-failover');
    config()->set('spotengine.cache.nzb_path', $cacheDirectory);

    $nzb = (new NzbDownloadService($nntpService, $enricher))->fetchNzb($spot);

    expect($nzb)->toContain('subject="fallback"');

    $cachePath = nestedCachePath($cacheDirectory, md5('nzb.'.$spot->id), 'nzb');
    @unlink($cachePath);
});
