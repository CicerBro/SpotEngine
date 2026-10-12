<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\NzbGenerator;
use App\Services\Nntp\SingleNntpDriver;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NzbDownloadService
{
    private const int GZIP_LEVEL = 8;

    private const string GZIP_MAGIC = "\x1f\x8b";

    public function __construct(
        private readonly NntpService $nntpService,
        private readonly SpotEnricher $enricher,
    ) {}

    /**
     * Fetch the NZB content for a spot, using a file cache to avoid repeated NNTP requests.
     *
     * Enriches the spot first if needed, then checks the local cache before
     * falling back to an NNTP fetch.
     *
     * @throws HttpException
     */
    public function fetchNzb(Spot $spot): string
    {
        $this->enricher->enrich($spot);

        abort_if(empty($spot->nzb_segments), 404, 'No NZB data available.');

        return $this->decodeGzippedNzb($this->fetchGzippedNzbForSpot($spot));
    }

    /**
     * Fetch the gzipped NZB content for a spot.
     *
     * @throws HttpException
     */
    public function fetchGzippedNzb(Spot $spot): string
    {
        $this->enricher->enrich($spot);

        abort_if(empty($spot->nzb_segments), 404, 'No NZB data available.');

        return $this->fetchGzippedNzbForSpot($spot);
    }

    /**
     * Fetch NZB using a pre-connected NNTP driver (for batch operations like precaching).
     */
    public function fetchNzbWithDriver(Spot $spot, SingleNntpDriver $nntp): string
    {
        $cached = $this->readGzippedCache($spot);

        if ($cached !== null) {
            return $this->decodeGzippedNzb($cached);
        }

        $cachePath = $this->cachePath($spot);

        $config = $this->nntpService->getConfig();

        try {
            $nzb = $this->fetchNzbFromNntp($spot, $nntp, $config);
        } catch (\RuntimeException $primaryException) {
            $nzb = $this->fetchFromAlternateProvider($spot, $config, $primaryException);
        }

        $this->writeToCache($cachePath, $this->encodeNzb($nzb));

        return $nzb;
    }

    /**
     * Build a sanitized filename from a spot title.
     */
    public function filename(Spot $spot): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $spot->title);

        return substr((string) $clean, 0, 100) . '.nzb';
    }

    /**
     * Get the cache file path for a spot's NZB.
     */
    public function cachePath(Spot $spot): string
    {
        return $this->buildCachePath($spot, 'nzb.gz');
    }

    public function isCached(Spot $spot): bool
    {
        return is_file($this->cachePath($spot)) || is_file($this->legacyCachePath($spot));
    }

    private function fetchGzippedNzbForSpot(Spot $spot): string
    {
        $cached = $this->readGzippedCache($spot);

        if ($cached !== null) {
            return $cached;
        }

        $cachePath = $this->cachePath($spot);

        $config = $this->nntpService->getConfig();
        $nntp = $this->nntpService->makeDriver(driver: 'single');

        try {
            $nzb = $this->fetchWithConnectedDriver($spot, $nntp, $config);
        } catch (\RuntimeException $primaryException) {
            $nzb = $this->fetchFromAlternateProvider($spot, $config, $primaryException);
        }

        $gzippedNzb = $this->encodeNzb($nzb);
        $this->writeToCache($cachePath, $gzippedNzb);

        return $gzippedNzb;
    }

    private function legacyCachePath(Spot $spot): string
    {
        return $this->buildCachePath($spot, 'nzb');
    }

    private function buildCachePath(Spot $spot, string $extension): string
    {
        return nestedCachePath(
            (string) config('spotengine.cache.nzb_path'),
            md5('nzb.' . $spot->id),
            $extension,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function fetchNzbFromNntp(Spot $spot, SingleNntpDriver $nntp, array $config): string
    {
        $generator = new NzbGenerator($nntp);

        return $generator->fetchNzb($spot->nzb_segments, $config['groups']['nzb'] ?? $config['groups']['spots']);
    }

    /** @param array<string, mixed> $config */
    private function fetchWithConnectedDriver(Spot $spot, SingleNntpDriver $nntp, array $config): string
    {
        try {
            $nntp->connect();

            return $this->fetchNzbFromNntp($spot, $nntp, $config);
        } finally {
            $nntp->quit();
        }
    }

    /** @param array<string, mixed> $config */
    private function fetchFromAlternateProvider(
        Spot $spot,
        array $config,
        \RuntimeException $primaryException,
    ): string {
        $alternate = $this->nntpService->makeAlternateDriver();

        if (! $alternate instanceof SingleNntpDriver) {
            throw $primaryException;
        }

        return $this->fetchWithConnectedDriver($spot, $alternate, $config);
    }

    private function readGzippedCache(Spot $spot): ?string
    {
        $cachePath = $this->cachePath($spot);

        if (is_file($cachePath)) {
            return $this->normalizeCachedPayload($cachePath);
        }

        $legacyPath = $this->legacyCachePath($spot);

        if (! is_file($legacyPath)) {
            return null;
        }

        $cached = $this->readCacheFile($legacyPath);

        if ($cached === null) {
            return null;
        }

        $gzipped = $this->isGzipped($cached) ? $cached : $this->encodeNzb($cached);
        if (! $this->validateCachedGzip($legacyPath, $gzipped)) {
            return null;
        }
        $this->writeToCache($cachePath, $gzipped);
        @unlink($legacyPath);

        return $gzipped;
    }

    private function normalizeCachedPayload(string $cachePath): ?string
    {
        $cached = $this->readCacheFile($cachePath);

        if ($cached === null) {
            return null;
        }

        if ($this->isGzipped($cached)) {
            return $this->validateCachedGzip($cachePath, $cached) ? $cached : null;
        }

        $gzipped = $this->encodeNzb($cached);
        $this->writeToCache($cachePath, $gzipped);

        return $gzipped;
    }

    private function readCacheFile(string $path): ?string
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return null;
        }

        try {
            $prefix = fread($stream, 2);
            if ($prefix === false) {
                return null;
            }
            $maxBytes = $this->isGzipped($prefix)
                ? max(1, (int) config('spotengine.payload_limits.nzb_compressed_bytes', 16_777_216))
                : max(1, (int) config('spotengine.payload_limits.nzb_output_bytes', 67_108_864));
            $remainder = stream_get_contents($stream, max(1, $maxBytes - strlen($prefix) + 1));
            if ($remainder === false) {
                return null;
            }
            $cached = $prefix . $remainder;
        } finally {
            fclose($stream);
        }

        if (strlen($cached) > $maxBytes) {
            @unlink($path);

            return null;
        }

        return $cached;
    }

    private function encodeNzb(string $nzb): string
    {
        if (strlen($nzb) > max(1, (int) config('spotengine.payload_limits.nzb_output_bytes', 67_108_864))) {
            throw new \RuntimeException('NZB cache output exceeds payload limit.');
        }
        $gzipped = gzencode($nzb, self::GZIP_LEVEL);

        if ($gzipped === false) {
            throw new \RuntimeException('Unable to gzip NZB cache payload.');
        }

        if (strlen($gzipped) > max(1, (int) config('spotengine.payload_limits.nzb_compressed_bytes', 16_777_216))) {
            throw new \RuntimeException('Compressed NZB cache exceeds payload limit.');
        }

        return $gzipped;
    }

    private function decodeGzippedNzb(string $gzippedNzb): string
    {
        if (strlen($gzippedNzb) > max(1, (int) config('spotengine.payload_limits.nzb_compressed_bytes', 16_777_216))) {
            throw new \RuntimeException('Compressed NZB cache exceeds payload limit.');
        }
        $nzb = @gzdecode($gzippedNzb, max(1, (int) config('spotengine.payload_limits.nzb_output_bytes', 67_108_864)));

        if ($nzb === false) {
            throw new \RuntimeException('Unable to decode gzipped NZB cache payload.');
        }

        return $nzb;
    }

    private function validateCachedGzip(string $path, string $data): bool
    {
        try {
            $this->decodeGzippedNzb($data);

            return true;
        } catch (\RuntimeException) {
            @unlink($path);

            return false;
        }
    }

    private function isGzipped(string $data): bool
    {
        return str_starts_with($data, self::GZIP_MAGIC);
    }

    private function writeToCache(string $cachePath, string $data): void
    {
        $dir = dirname($cachePath);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create NZB cache directory.');
        }

        $temporaryPath = $cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $stream = @fopen($temporaryPath, 'xb');

        if ($stream === false) {
            throw new \RuntimeException('Unable to open temporary NZB cache file.');
        }

        try {
            $offset = 0;
            $length = strlen($data);
            while ($offset < $length) {
                $written = @fwrite($stream, substr($data, $offset, 65_536));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException('Unable to write complete NZB cache payload.');
                }
                $offset += $written;
            }
            if (! @fflush($stream)) {
                throw new \RuntimeException('Unable to flush NZB cache payload.');
            }
            if (! @fclose($stream)) {
                throw new \RuntimeException('Unable to close NZB cache payload.');
            }
            if (! @rename($temporaryPath, $cachePath)) {
                throw new \RuntimeException('Unable to publish NZB cache payload.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }
}
