<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\NzbGenerator;

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
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
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
        $nntp->connect();

        try {
            $generator = new NzbGenerator($nntp);
            $nzb = $generator->fetchNzb($spot->nzb_segments, $config['groups']['nzb'] ?? $config['groups']['spots']);
        } finally {
            try {
                $nntp->quit();
            } catch (\Throwable) {
                // Ignore quit errors.
            }
        }

        $dir = dirname($cachePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($cachePath, $nzb, LOCK_EX);

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

    private function cachePath(Spot $spot): string
    {
        return config('spotengine.cache.nzb_path').DIRECTORY_SEPARATOR.md5('nzb.'.$spot->id).'.nzb';
    }
}
