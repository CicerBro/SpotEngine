<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\NzbDownloadService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class PrecacheSpots extends Command
{
    protected $signature = 'spot:precache
                            {--type=both : What to pre-cache (nzb, images, both)}
                            {--batch=100 : Spots per batch}
                            {--limit= : Max spots to process}';

    protected $description = 'Pre-warm the NZB and image file caches by fetching from NNTP';

    private bool $shouldStop = false;

    public function handle(NntpService $nntpService, NzbDownloadService $nzbService): int
    {
        $type = (string) $this->option('type');
        $batchSize = (int) $this->option('batch');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! in_array($type, ['nzb', 'images', 'both'], true)) {
            $this->error("Invalid type '{$type}'. Use: nzb, images, or both.");

            return self::FAILURE;
        }

        if (! $this->checkRetention($type)) {
            return self::SUCCESS;
        }

        $this->registerSignalHandler();

        $results = [];

        if ($type === 'nzb' || $type === 'both') {
            $results['NZB'] = $this->precacheNzbs($nntpService, $nzbService, $batchSize, $limit);
        }

        if ($type === 'images' || $type === 'both') {
            $results['Images'] = $this->precacheImages($nntpService, $batchSize, $limit);
        }

        $this->newLine();
        $this->table(['Type', 'Cached', 'Skipped', 'Failed'], array_map(
            fn (string $label, array $counts) => [$label, $counts['cached'], $counts['skipped'], $counts['failed']],
            array_keys($results),
            array_values($results),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{cached: int, skipped: int, failed: int}
     */
    private function precacheNzbs(NntpService $nntpService, NzbDownloadService $nzbService, int $batchSize, ?int $limit): array
    {
        $this->info('Pre-caching NZBs…');

        /** @var SingleNntpDriver $nntp */
        $nntp = $nntpService->makeDriver(driver: 'single');
        $nntp->connect();

        $cached = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;
        $lastId = PHP_INT_MAX;

        try {
            while (! $this->shouldStop) {
                $queryLimit = $limit !== null ? min($batchSize, $limit - $processed) : $batchSize;

                if ($queryLimit <= 0) {
                    break;
                }

                /** @var Collection<int, Spot> $batch */
                $batch = Spot::query()
                    ->whereRaw("nzb_segments != '[]'::jsonb")
                    ->where('id', '<', $lastId)
                    ->select(['id', 'message_id', 'nzb_segments'])
                    ->orderBy('id', 'desc')
                    ->limit($queryLimit)
                    ->get();

                if ($batch->isEmpty()) {
                    break;
                }

                $lastId = (int) $batch->last()->id;

                foreach ($batch as $spot) {
                    if ($this->shouldStop) {
                        break;
                    }

                    $processed++;

                    if (file_exists($nzbService->cachePath($spot))) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $nzbService->fetchNzbWithDriver($spot, $nntp);
                        $cached++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::debug('spot:precache NZB failed', [
                            'spot_id' => $spot->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $this->outputProgress('NZB', $cached, $skipped, $failed);
                }

                if ($batch->count() < $queryLimit) {
                    break;
                }
            }
        } finally {
            try {
                $nntp->quit();
            } catch (\Throwable) {
                // Ignore quit errors.
            }
        }

        return ['cached' => $cached, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * @return array{cached: int, skipped: int, failed: int}
     */
    private function precacheImages(NntpService $nntpService, int $batchSize, ?int $limit): array
    {
        $this->info('Pre-caching images…');

        $config = $nntpService->getConfig();

        /** @var SingleNntpDriver $nntp */
        $nntp = $nntpService->makeDriver(driver: 'single');
        $nntp->connect();

        $cached = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;
        $groupSelected = false;

        try {
            while (! $this->shouldStop) {
                $queryLimit = $limit !== null ? min($batchSize, $limit - $processed) : $batchSize;

                if ($queryLimit <= 0) {
                    break;
                }

                /** @var Collection<int, Spot> $batch */
                $batch = Spot::query()
                    ->whereNotNull('image_segment')
                    ->select(['id', 'image_segment'])
                    ->orderBy('id', 'desc')
                    ->offset($processed)
                    ->limit($queryLimit)
                    ->get();

                if ($batch->isEmpty()) {
                    break;
                }

                foreach ($batch as $spot) {
                    if ($this->shouldStop) {
                        break;
                    }

                    $processed++;

                    $cachePath = nestedCachePath(
                        (string) config('spotengine.cache.image_path'),
                        md5((string) $spot->image_segment),
                        'img',
                    );

                    if (file_exists($cachePath)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        if (! $groupSelected) {
                            $nntp->group($config['groups']['spots']);
                            $groupSelected = true;
                        }

                        $body = $nntp->body($spot->image_segment);

                        if ($body === '' || $body === '0') {
                            $failed++;

                            continue;
                        }

                        $imageData = $this->decodeSpotImage($body);

                        if ($imageData === '' || $imageData === '0') {
                            $failed++;

                            continue;
                        }

                        $dir = dirname($cachePath);

                        if (! is_dir($dir)) {
                            mkdir($dir, 0755, true);
                        }

                        file_put_contents($cachePath, $imageData, LOCK_EX);
                        $cached++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::debug('spot:precache image failed', [
                            'spot_id' => $spot->id,
                            'error' => $e->getMessage(),
                        ]);
                    }

                    $this->outputProgress('Image', $cached, $skipped, $failed);
                }

                if ($batch->count() < $queryLimit) {
                    break;
                }
            }
        } finally {
            try {
                $nntp->quit();
            } catch (\Throwable) {
                // Ignore quit errors.
            }
        }

        return ['cached' => $cached, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function decodeSpotImage(string $data): string
    {
        $data = str_replace(['=C', '=B', '=A', '=D'], ["\n", "\r", "\0", '='], $data);
        $data = rtrim($data, "\r\n");

        $decompressed = @gzinflate($data);
        if ($decompressed !== false && $decompressed !== '') {
            return $decompressed;
        }

        return $data;
    }

    private function outputProgress(string $type, int $cached, int $skipped, int $failed): void
    {
        $total = $cached + $skipped + $failed;

        if ($total % 10 === 0) {
            $this->output->write("\r  {$type}: {$cached} cached, {$skipped} skipped, {$failed} failed");
        }
    }

    /**
     * Warn when cache retention is active and ask for confirmation to continue.
     */
    private function checkRetention(string $type): bool
    {
        $nzbRetention = (int) config('spotengine.cache.nzb_retention_days');
        $imageRetention = (int) config('spotengine.cache.image_retention_days');
        $hasWarning = false;

        if (($type === 'nzb' || $type === 'both') && $nzbRetention > 0) {
            $this->warn("NZB cache retention is set to {$nzbRetention} days.");
            $this->warn('Pre-cached files will be deleted by spot:prune-cache after that period.');
            $this->warn('Set CACHE_NZB_RETENTION_DAYS=0 to disable pruning.');
            $hasWarning = true;
        }

        if (($type === 'images' || $type === 'both') && $imageRetention > 0) {
            $this->warn("Image cache retention is set to {$imageRetention} days.");
            $this->warn('Pre-cached files will be deleted by spot:prune-cache after that period.');
            $this->warn('Set CACHE_IMAGE_RETENTION_DAYS=0 to disable pruning.');
            $hasWarning = true;
        }

        if ($hasWarning && ! $this->confirm('Continue anyway?')) {
            $this->info('Aborted.');

            return false;
        }

        return true;
    }

    private function registerSignalHandler(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (): void {
            $this->newLine();
            $this->warn('Stopping after current item…');
            $this->shouldStop = true;
        });
    }
}
