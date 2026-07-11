<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\Nntp\SpotImageDecoder;

class SpotImageService
{
    private const int MAX_IMAGE_BYTES = 25_000_000;

    private const int MAX_IMAGE_PIXELS = 40_000_000;

    public function __construct(
        private readonly NntpService $nntpService,
        private readonly SpotEnricher $enricher,
        private readonly SpotImageDecoder $decoder,
    ) {}

    /**
     * @return array{data: string, content_type: string, from_cache: bool}|null
     */
    public function fetch(Spot $spot): ?array
    {
        $this->enricher->enrich($spot);
        $segments = $this->segments($spot);

        if ($segments === []) {
            return null;
        }

        $cached = $this->readCache($segments);

        if ($cached !== null) {
            return $cached + ['from_cache' => true];
        }

        $config = $this->nntpService->getConfig();
        $primary = $this->nntpService->makeDriver(driver: 'single');

        try {
            return $this->fetchUsingConnectedDriver($segments, $primary, $config);
        } catch (\Throwable $primaryException) {
            $alternate = $this->nntpService->makeAlternateDriver();

            if (! $alternate instanceof SingleNntpDriver) {
                throw $primaryException;
            }

            return $this->fetchUsingConnectedDriver($segments, $alternate, $config);
        }
    }

    /**
     * Fetch with a caller-owned NNTP connection, primarily for cache warming.
     *
     * @return array{data: string, content_type: string, from_cache: bool}|null
     */
    public function fetchWithDriver(
        Spot $spot,
        SingleNntpDriver $driver,
        bool $selectGroup = true,
    ): ?array {
        $segments = $this->segments($spot);

        if ($segments === []) {
            return null;
        }

        $cached = $this->readCache($segments);

        if ($cached !== null) {
            return $cached + ['from_cache' => true];
        }

        if ($selectGroup) {
            $config = $this->nntpService->getConfig();
            $driver->group((string) $config['groups']['spots']);
        }

        return $this->fetchBodies($segments, $driver);
    }

    /**
     * @return list<string>
     */
    public function segments(Spot $spot): array
    {
        $segments = $spot->getAttribute('image_segments');

        if (\is_array($segments)) {
            $segments = array_values(array_filter(
                $segments,
                static fn (mixed $segment): bool => \is_string($segment) && $segment !== '' && $segment !== '0',
            ));

            if ($segments !== []) {
                return $segments;
            }
        }

        return filled($spot->image_segment) ? [(string) $spot->image_segment] : [];
    }

    /**
     * @param  list<string>  $segments
     */
    public function cachePath(array $segments): string
    {
        $version = (int) config('spotengine.cache.image_version', 2);
        $cacheKey = "spot-image-v{$version}\0".implode("\0", $segments);

        return nestedCachePath(
            (string) config('spotengine.cache.image_path'),
            md5($cacheKey),
            'img',
        );
    }

    /**
     * @param  list<string>  $segments
     * @param  array<string, mixed>  $config
     * @return array{data: string, content_type: string, from_cache: bool}
     */
    private function fetchUsingConnectedDriver(
        array $segments,
        SingleNntpDriver $driver,
        array $config,
    ): array {
        try {
            $driver->connect(showProgress: false);
            $driver->group((string) $config['groups']['spots']);

            return $this->fetchBodies($segments, $driver);
        } finally {
            try {
                $driver->quit();
            } catch (\Throwable) {
                // The image result is still valid when only QUIT fails.
            }
        }
    }

    /**
     * @param  list<string>  $segments
     * @return array{data: string, content_type: string, from_cache: bool}
     */
    private function fetchBodies(array $segments, SingleNntpDriver $driver): array
    {
        $articleBodies = [];

        foreach ($segments as $segment) {
            $articleBodies[] = $driver->body($segment);
        }

        $imageData = $this->decoder->decode($articleBodies);
        $contentType = $this->validateImage($imageData);
        $this->writeCache($this->cachePath($segments), $imageData);

        return [
            'data' => $imageData,
            'content_type' => $contentType,
            'from_cache' => false,
        ];
    }

    /**
     * @param  list<string>  $segments
     * @return array{data: string, content_type: string}|null
     */
    private function readCache(array $segments): ?array
    {
        $cachePath = $this->cachePath($segments);

        if (! is_file($cachePath)) {
            return null;
        }

        $imageData = file_get_contents($cachePath);

        if ($imageData === false) {
            return null;
        }

        try {
            $contentType = $this->validateImage($imageData);
        } catch (\UnexpectedValueException) {
            @unlink($cachePath);

            return null;
        }

        @touch($cachePath);

        return ['data' => $imageData, 'content_type' => $contentType];
    }

    private function validateImage(string $imageData): string
    {
        $length = strlen($imageData);

        if ($length === 0 || $length > self::MAX_IMAGE_BYTES) {
            throw new \UnexpectedValueException('The decoded preview image has an invalid size.');
        }

        $imageInfo = @getimagesizefromstring($imageData);
        $contentType = $imageInfo['mime'] ?? null;
        $width = $imageInfo[0] ?? 0;
        $height = $imageInfo[1] ?? 0;

        if (! \is_string($contentType)
            || ! \in_array($contentType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'], true)
            || $width < 1
            || $height < 1
            || $width * $height > self::MAX_IMAGE_PIXELS) {
            throw new \UnexpectedValueException('The decoded NNTP payload is not a supported preview image.');
        }

        if (\function_exists('imagecreatefromstring')) {
            $image = @imagecreatefromstring($imageData);

            if ($image === false) {
                throw new \UnexpectedValueException('The decoded preview image is corrupt.');
            }
        }

        return $contentType;
    }

    private function writeCache(string $cachePath, string $imageData): void
    {
        $directory = dirname($cachePath);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new \RuntimeException("Unable to create image cache directory: {$directory}");
        }

        $temporaryPath = $cachePath.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (file_put_contents($temporaryPath, $imageData, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write the preview image cache.');
        }

        if (! @rename($temporaryPath, $cachePath)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish the preview image cache.');
        }
    }
}
