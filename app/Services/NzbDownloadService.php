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

        $cachePath = $this->cachePath($spot);

        if (file_exists($cachePath)) {
            $nzb = file_get_contents($cachePath);

            if ($nzb !== false) {
                return $nzb;
            }
        }

        $config = $this->nntpService->getConfig();
        $nntp = $this->nntpService->makeDriver(driver: 'single');

        try {
            $nzb = $this->fetchWithConnectedDriver($spot, $nntp, $config);
        } catch (\RuntimeException $primaryException) {
            $nzb = $this->fetchFromAlternateProvider($spot, $config, $primaryException);
        }

        $this->writeToCache($cachePath, $nzb);

        return $nzb;
    }

    /**
     * Fetch NZB using a pre-connected NNTP driver (for batch operations like precaching).
     */
    public function fetchNzbWithDriver(Spot $spot, SingleNntpDriver $nntp): string
    {
        $cachePath = $this->cachePath($spot);

        if (file_exists($cachePath)) {
            $nzb = file_get_contents($cachePath);

            if ($nzb !== false) {
                return $nzb;
            }
        }

        $config = $this->nntpService->getConfig();

        try {
            $nzb = $this->fetchNzbFromNntp($spot, $nntp, $config);
        } catch (\RuntimeException $primaryException) {
            $nzb = $this->fetchFromAlternateProvider($spot, $config, $primaryException);
        }

        $this->writeToCache($cachePath, $nzb);

        return $nzb;
    }

    /**
     * Build a sanitized filename from a spot title.
     */
    public function filename(Spot $spot): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $spot->title);

        return substr((string) $clean, 0, 100).'.nzb';
    }

    /**
     * Get the cache file path for a spot's NZB.
     */
    public function cachePath(Spot $spot): string
    {
        return nestedCachePath(
            (string) config('spotengine.cache.nzb_path'),
            md5('nzb.'.$spot->id),
            'nzb',
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

    private function writeToCache(string $cachePath, string $data): void
    {
        $dir = dirname($cachePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($cachePath, $data, LOCK_EX);
    }
}
