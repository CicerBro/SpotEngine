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
    $this->cacheDirectory = storage_path('framework/testing/nzb-download-' . bin2hex(random_bytes(4)));
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

    expect($service->cachePath($spot))->toEndWith('.nzb.gz')
        ->and($cached)->toStartWith("\x1f\x8b")
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
    $legacyPath = nestedCachePath($this->cacheDirectory, md5('nzb.' . $spot->id), 'nzb');
    File::ensureDirectoryExists(dirname($legacyPath));
    File::put($legacyPath, $nzb);

    $plain = $service->fetchNzb($spot);
    $cached = File::get($service->cachePath($spot));

    expect($plain)->toBe($nzb)
        ->and($service->cachePath($spot))->toEndWith('.nzb.gz')
        ->and(File::exists($legacyPath))->toBeFalse()
        ->and($cached)->toStartWith("\x1f\x8b")
        ->and(gzdecode($cached))->toBe($nzb);
});

test('legacy gzipped NZB cache files are migrated to nzb.gz extension', function () {
    $spot = new Spot([
        'title' => 'Legacy gzipped NZB',
        'nzb_segments' => ['segment@test'],
    ]);
    $spot->setAttribute('id', 457);
    $nzb = '<?xml version="1.0"?><nzb><file subject="legacy-gzip"/></nzb>';
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
    $legacyPath = nestedCachePath($this->cacheDirectory, md5('nzb.' . $spot->id), 'nzb');
    File::ensureDirectoryExists(dirname($legacyPath));
    File::put($legacyPath, $gzipped);

    $cached = $service->fetchGzippedNzb($spot);

    expect($cached)->toBe($gzipped)
        ->and($service->cachePath($spot))->toEndWith('.nzb.gz')
        ->and(File::exists($legacyPath))->toBeFalse()
        ->and(File::get($service->cachePath($spot)))->toBe($gzipped);
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
        ->and($service->cachePath($spot))->toEndWith('.nzb.gz')
        ->and($cached)->toStartWith("\x1f\x8b")
        ->and(gzdecode($cached))->toBe($nzb);
});

test('cache normalization replaces the file atomically for already open readers', function () {
    $spot = new Spot(['title' => 'Atomic cache', 'nzb_segments' => ['segment@test']]);
    $spot->setAttribute('id', 900);
    $nzb = '<nzb><file subject="atomic"/></nzb>';
    $nntp = Mockery::mock(NntpService::class);
    $nntp->shouldNotReceive('makeDriver');
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);
    $service = new NzbDownloadService($nntp, $enricher);
    $path = $service->cachePath($spot);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $nzb);
    $reader = fopen($path, 'rb');

    try {
        $cached = $service->fetchGzippedNzb($spot);

        expect(stream_get_contents($reader))->toBe($nzb);
        expect(gzdecode(File::get($path)))->toBe($nzb);
        expect($cached)->toBe(File::get($path));
        expect(glob($path . '.*.tmp'))->toBe([]);
    } finally {
        fclose($reader);
    }
});

test('failed NZB cache publication cleans temporary files and preserves the legacy cache', function () {
    $spot = new Spot(['title' => 'Publication failure', 'nzb_segments' => ['segment@test']]);
    $spot->setAttribute('id', 901);
    $nntp = Mockery::mock(NntpService::class);
    $nntp->shouldNotReceive('makeDriver');
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);
    $service = new NzbDownloadService($nntp, $enricher);
    $path = $service->cachePath($spot);
    $legacyPath = nestedCachePath($this->cacheDirectory, md5('nzb.' . $spot->id), 'nzb');
    File::ensureDirectoryExists($path);
    File::put($path . '/existing', 'preserved');
    File::put($legacyPath, '<nzb></nzb>');

    expect(fn () => $service->fetchGzippedNzb($spot))->toThrow(RuntimeException::class, 'publish');
    expect(glob($path . '.*.tmp'))->toBe([]);
    expect(File::get($path . '/existing'))->toBe('preserved');
    expect(File::get($legacyPath))->toBe('<nzb></nzb>');
});

test('oversized and corrupt NZB caches are discarded and refetched with bounded decoding', function (string $payload) {
    config()->set('spotengine.payload_limits.nzb_output_bytes', 64);
    config()->set('spotengine.payload_limits.nzb_compressed_bytes', 48);
    $spot = new Spot(['title' => 'Invalid cache', 'nzb_segments' => ['segment@test']]);
    $spot->setAttribute('id', 902);
    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('group')->once()->with('free.pt');
    $driver->shouldReceive('body')->once()->with('segment@test')->andReturn('<nzb></nzb>');
    $nntp = Mockery::mock(NntpService::class);
    $nntp->shouldReceive('getConfig')->once()->andReturn(['groups' => ['spots' => 'free.pt']]);
    $enricher = Mockery::mock(SpotEnricher::class);
    $service = new NzbDownloadService($nntp, $enricher);
    $path = $service->cachePath($spot);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $payload);

    expect($service->fetchNzbWithDriver($spot, $driver))->toBe('<nzb></nzb>');
    expect(gzdecode(File::get($path)))->toBe('<nzb></nzb>');
})->with([
    'plain bytes' => str_repeat('x', 65),
    'expanded gzip output' => gzencode(str_repeat('x', 4096)),
    'compressed bytes' => "\x1f\x8b" . str_repeat('x', 47),
    'corrupt gzip' => "\x1f\x8bbroken",
]);

test('NZB cache directory creation failures are reported without publishing a file', function () {
    $spot = new Spot(['title' => 'Directory failure', 'nzb_segments' => ['segment@test']]);
    $spot->setAttribute('id', 903);
    File::ensureDirectoryExists(dirname($this->cacheDirectory));
    File::put($this->cacheDirectory, 'existing file');
    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('group')->once()->with('free.pt');
    $driver->shouldReceive('body')->once()->with('segment@test')->andReturn('<nzb></nzb>');
    $nntp = Mockery::mock(NntpService::class);
    $nntp->shouldReceive('getConfig')->once()->andReturn(['groups' => ['spots' => 'free.pt']]);
    $service = new NzbDownloadService($nntp, Mockery::mock(SpotEnricher::class));

    try {
        expect(fn () => $service->fetchNzbWithDriver($spot, $driver))->toThrow(RuntimeException::class, 'directory');
        expect(File::get($this->cacheDirectory))->toBe('existing file');
    } finally {
        File::delete($this->cacheDirectory);
    }
});
