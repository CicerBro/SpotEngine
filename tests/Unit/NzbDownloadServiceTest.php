<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\NzbDownloadService;
use App\Services\SpotEnricher;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->cacheDirectory = storage_path('framework/testing/nzb-download-'.bin2hex(random_bytes(4)));
    config()->set('spotengine.cache.nzb_path', $this->cacheDirectory);
});

afterEach(function () {
    File::deleteDirectory($this->cacheDirectory);
});

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

    $service = new NzbDownloadService($nntpService, $enricher);

    $nzb = $service->fetchNzb($spot);

    expect($nzb)->toContain('subject="fallback"');

    $cached = File::get($service->cachePath($spot));

    expect($cached)->toStartWith("\x1f\x8b")
        ->and(gzdecode($cached))->toBe($nzb);
});

test('gzipped cached NZBs are returned without opening NNTP', function () {
    $spot = new Spot([
        'title' => 'Cached NZB',
        'nzb_segments' => ['segment@test'],
    ]);
    $spot->setAttribute('id', 123);
    $nzb = '<?xml version="1.0"?><nzb><file subject="cached"/></nzb>';
    $gzipped = gzencode($nzb, 8);

    if ($gzipped === false) {
        throw new RuntimeException('Unable to create gzipped NZB test fixture.');
    }

    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldNotReceive('makeDriver');
    $nntpService->shouldNotReceive('getConfig');
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);
    $service = new NzbDownloadService($nntpService, $enricher);
    File::ensureDirectoryExists(dirname($service->cachePath($spot)));
    File::put($service->cachePath($spot), $gzipped);

    $cached = $service->fetchGzippedNzb($spot);

    expect($cached)->toBe($gzipped)
        ->and(gzdecode($cached))->toBe($nzb);
});

test('legacy plain NZB cache entries are migrated to gzip', function () {
    $spot = new Spot([
        'title' => 'Legacy NZB',
        'nzb_segments' => ['segment@test'],
    ]);
    $spot->setAttribute('id', 456);
    $nzb = '<?xml version="1.0"?><nzb><file subject="legacy"/></nzb>';

    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldNotReceive('makeDriver');
    $nntpService->shouldNotReceive('getConfig');
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);
    $service = new NzbDownloadService($nntpService, $enricher);
    File::ensureDirectoryExists(dirname($service->cachePath($spot)));
    File::put($service->cachePath($spot), $nzb);

    $plain = $service->fetchNzb($spot);
    $cached = File::get($service->cachePath($spot));

    expect($plain)->toBe($nzb)
        ->and($cached)->toStartWith("\x1f\x8b")
        ->and(gzdecode($cached))->toBe($nzb);
});

test('precache fetches return plain NZB content while storing gzip', function () {
    $spot = new Spot([
        'title' => 'Precached NZB',
        'nzb_segments' => ['segment@test'],
    ]);
    $spot->setAttribute('id', 789);
    $nzb = '<?xml version="1.0"?><nzb><file subject="precache"/></nzb>';

    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('group')->once()->with('free.pt');
    $driver->shouldReceive('body')->once()->with('segment@test')->andReturn($nzb);
    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('getConfig')->once()->andReturn([
        'groups' => ['spots' => 'free.pt'],
    ]);
    $nntpService->shouldNotReceive('makeDriver');
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldNotReceive('enrich');
    $service = new NzbDownloadService($nntpService, $enricher);

    $plain = $service->fetchNzbWithDriver($spot, $driver);
    $cached = File::get($service->cachePath($spot));

    expect($plain)->toBe($nzb)
        ->and($cached)->toStartWith("\x1f\x8b")
        ->and(gzdecode($cached))->toBe($nzb);
});
