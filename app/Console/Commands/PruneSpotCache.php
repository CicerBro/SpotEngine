<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneSpotCache extends Command
{
    protected $signature = 'spot:prune-cache
                            {--nzb-days= : Override NZB retention days from config}
                            {--image-days= : Override image retention days from config}';

    protected $description = 'Delete cached NZB and image files older than the configured retention period';

    public function handle(): int
    {
        $nzbDays = (int) ($this->option('nzb-days') ?? config('spotengine.cache.nzb_retention_days'));
        $imageDays = (int) ($this->option('image-days') ?? config('spotengine.cache.image_retention_days'));

        $nzbPruned = $this->pruneDirectory(config('spotengine.cache.nzb_path'), $nzbDays);
        $imagePruned = $this->pruneDirectory(config('spotengine.cache.image_path'), $imageDays);

        $this->info('Cache pruned.');
        $this->table(['Type', 'Retention', 'Deleted'], [
            ['NZB', "{$nzbDays} days", $nzbPruned],
            ['Images', "{$imageDays} days", $imagePruned],
        ]);

        return self::SUCCESS;
    }

    private function pruneDirectory(string $path, int $days): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $cutoff = time() - ($days * 86400);
        $deleted = 0;

        foreach (new \FilesystemIterator($path) as $file) {
            if ($file->isFile() && $file->getMTime() < $cutoff) {
                @unlink($file->getPathname());
                $deleted++;
            }
        }

        return $deleted;
    }
}
