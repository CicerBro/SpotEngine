<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PruneSpotCache extends Command
{
    protected $signature = 'spot:prune-cache
                            {--nzb-days= : Override NZB retention days from config}
                            {--image-days= : Override image retention days from config}
                            {--clear : Delete ALL cached NZB and image files regardless of retention settings}';

    protected $description = 'Delete cached NZB and image files older than the configured retention period';

    public function handle(): int
    {
        if ($this->option('clear')) {
            return $this->handleClear();
        }

        $nzbDays = (int) ($this->option('nzb-days') ?? config('spotengine.cache.nzb_retention_days'));
        $imageDays = (int) ($this->option('image-days') ?? config('spotengine.cache.image_retention_days'));

        if ($nzbDays === 0) {
            $this->info('NZB pruning disabled (retention = 0).');
            $nzbPruned = 0;
        } else {
            $nzbPruned = $this->pruneDirectory((string) config('spotengine.cache.nzb_path'), $nzbDays);
        }

        if ($imageDays === 0) {
            $this->info('Image pruning disabled (retention = 0).');
            $imagePruned = 0;
        } else {
            $imagePruned = $this->pruneDirectory((string) config('spotengine.cache.image_path'), $imageDays);
        }

        $this->info('Cache pruned.');
        $this->table(['Type', 'Retention', 'Deleted'], [
            ['NZB', $nzbDays === 0 ? 'disabled' : "{$nzbDays} days", $nzbPruned],
            ['Images', $imageDays === 0 ? 'disabled' : "{$imageDays} days", $imagePruned],
        ]);

        return self::SUCCESS;
    }

    private function handleClear(): int
    {
        $nzbPath = (string) config('spotengine.cache.nzb_path');
        $imagePath = (string) config('spotengine.cache.image_path');

        $nzbCount = $this->countFiles($nzbPath);
        $imageCount = $this->countFiles($imagePath);

        if ($nzbCount === 0 && $imageCount === 0) {
            $this->info('Cache is already empty.');

            return self::SUCCESS;
        }

        $this->warn('This will permanently delete ALL cached files:');
        $this->table(['Type', 'Files', 'Path'], [
            ['NZB', $nzbCount, $nzbPath],
            ['Images', $imageCount, $imagePath],
        ]);

        if (! $this->confirm('Are you sure you want to clear the entire cache?')) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $nzbDeleted = $this->clearDirectory($nzbPath);
        $imageDeleted = $this->clearDirectory($imagePath);

        $this->info('Cache cleared.');
        $this->table(['Type', 'Deleted'], [
            ['NZB', $nzbDeleted],
            ['Images', $imageDeleted],
        ]);

        return self::SUCCESS;
    }

    private function pruneDirectory(string $path, int $days): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $cutoff = time() - ($days * 86400);

        return $this->pruneRecursive($path, $cutoff, isRoot: true);
    }

    private function pruneRecursive(string $path, int $cutoff, bool $isRoot = false): int
    {
        $deleted = 0;

        foreach (new \FilesystemIterator($path) as $item) {
            if ($item->isDir()) {
                $deleted += $this->pruneRecursive($item->getPathname(), $cutoff);

                // Remove empty directories (but not the root cache directory)
                if ($this->isEmptyDirectory($item->getPathname())) {
                    @rmdir($item->getPathname());
                }
            } elseif ($item->isFile() && $item->getMTime() < $cutoff) {
                @unlink($item->getPathname());
                $deleted++;
            }
        }

        // Clean up this directory if empty (but not the root)
        if (! $isRoot && $this->isEmptyDirectory($path)) {
            @rmdir($path);
        }

        return $deleted;
    }

    private function clearDirectory(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $deleted = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            if ($item->isFile()) {
                @unlink($item->getPathname());
                $deleted++;
            } elseif ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }

        return $deleted;
    }

    private function countFiles(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $count = 0;

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        ) as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    private function isEmptyDirectory(string $path): bool
    {
        if (! is_dir($path)) {
            return false;
        }

        $iterator = new \FilesystemIterator($path);

        return ! $iterator->valid();
    }
}
