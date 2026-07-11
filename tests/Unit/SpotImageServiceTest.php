<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\Nntp\SpotImageDecoder;
use App\Services\SpotEnricher;
use App\Services\SpotImageService;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->imageCacheDirectory = storage_path('framework/testing/spot-images-'.bin2hex(random_bytes(4)));
    config()->set('spotengine.cache.image_path', $this->imageCacheDirectory);
    config()->set('spotengine.cache.image_version', 2);

    /** @var array{expected_png_base64: string, spotnet_multipart_body_base64: list<string>, corrupt_cached_payload_base64: string} $fixture */
    $fixture = require base_path('tests/Fixtures/Nntp/spot-image-payloads.php');
    $this->expectedImage = (string) base64_decode($fixture['expected_png_base64'], true);
    $this->articleBodies = array_map(
        static fn (string $body): string => (string) base64_decode($body, true),
        $fixture['spotnet_multipart_body_base64'],
    );
    $this->corruptImage = (string) base64_decode($fixture['corrupt_cached_payload_base64'], true);
});

afterEach(function () {
    File::deleteDirectory($this->imageCacheDirectory);
});

test('multipart previews are fetched, validated, and cached under a versioned key', function () {
    $spot = previewSpot(['part-1@spot.net', 'part-2@spot.net']);
    $driver = previewDriver($spot->image_segments, $this->articleBodies);
    $nntpService = previewNntpService($driver);
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);
    $service = new SpotImageService($nntpService, $enricher, new SpotImageDecoder);

    $legacyCachePath = nestedCachePath(
        $this->imageCacheDirectory,
        md5((string) $spot->image_segment),
        'img',
    );
    File::ensureDirectoryExists(dirname($legacyCachePath));
    File::put($legacyCachePath, $this->corruptImage);

    $image = $service->fetch($spot);

    expect($image)->not->toBeNull()
        ->and($image['data'])->toBe($this->expectedImage)
        ->and($image['content_type'])->toBe('image/png')
        ->and($image['from_cache'])->toBeFalse()
        ->and($service->cachePath($spot->image_segments))->not->toBe($legacyCachePath)
        ->and(File::get($service->cachePath($spot->image_segments)))->toBe($this->expectedImage);
});

test('valid cached previews are returned without opening an NNTP connection', function () {
    $spot = previewSpot(['cached@spot.net']);
    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldNotReceive('makeDriver');
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);
    $service = new SpotImageService($nntpService, $enricher, new SpotImageDecoder);
    $cachePath = $service->cachePath($spot->image_segments);
    File::ensureDirectoryExists(dirname($cachePath));
    File::put($cachePath, $this->expectedImage);

    $image = $service->fetch($spot);

    expect($image)->not->toBeNull()
        ->and($image['data'])->toBe($this->expectedImage)
        ->and($image['content_type'])->toBe('image/png')
        ->and($image['from_cache'])->toBeTrue();
});

test('a corrupt current-version cache entry is deleted and refetched', function () {
    $spot = previewSpot(['corrupt@spot.net']);
    $driver = previewDriver($spot->image_segments, [$this->articleBodies[0].$this->articleBodies[1]]);
    $nntpService = previewNntpService($driver);
    $enricher = Mockery::mock(SpotEnricher::class);
    $enricher->shouldReceive('enrich')->once()->with($spot);
    $service = new SpotImageService($nntpService, $enricher, new SpotImageDecoder);
    $cachePath = $service->cachePath($spot->image_segments);
    File::ensureDirectoryExists(dirname($cachePath));
    File::put($cachePath, $this->corruptImage);

    $image = $service->fetch($spot);

    expect($image)->not->toBeNull()
        ->and($image['from_cache'])->toBeFalse()
        ->and(File::get($cachePath))->toBe($this->expectedImage);
});

/**
 * @param  list<string>  $segments
 */
function previewSpot(array $segments): Spot
{
    return new Spot([
        'image_segment' => $segments[0] ?? null,
        'image_segments' => $segments,
        'nzb_segments' => [],
    ]);
}

/**
 * @param  list<string>  $segments
 * @param  list<string>  $bodies
 */
function previewDriver(array $segments, array $bodies): SingleNntpDriver
{
    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('connect')->once()->with(false);
    $driver->shouldReceive('group')->once()->with('free.pt');

    foreach ($segments as $index => $segment) {
        $driver->shouldReceive('body')->once()->with($segment)->andReturn($bodies[$index]);
    }

    $driver->shouldReceive('quit')->once();

    return $driver;
}

function previewNntpService(SingleNntpDriver $driver): NntpService
{
    $service = Mockery::mock(NntpService::class);
    $service->shouldReceive('getConfig')->once()->andReturn([
        'groups' => ['spots' => 'free.pt'],
    ]);
    $service->shouldReceive('makeDriver')->once()->with(null, 'single')->andReturn($driver);
    $service->shouldNotReceive('makeAlternateDriver');

    return $service;
}
