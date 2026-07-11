<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\SearchIndexSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('spot:search-sync {--batch= : Documents per Manticore request} {--limit= : Maximum queued spots to process}')]
#[Description('Synchronize durable spot changes to Manticore Search')]
class SyncSearchIndex extends Command
{
    public function handle(SearchIndexSynchronizer $synchronizer): int
    {
        $batchSize = $this->option('batch') !== null
            ? max(1, (int) $this->option('batch'))
            : max(1, (int) config('search.drivers.manticore.sync_batch_size', 500));
        $limit = $this->option('limit') !== null
            ? max(1, (int) $this->option('limit'))
            : null;

        try {
            $processed = Cache::lock('spot-search-index-maintenance', 3600)->get(
                fn (): int => $synchronizer->synchronizePending($batchSize, $limit),
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($processed === false) {
            $this->warn('Another search index maintenance command is already running.');

            return self::FAILURE;
        }

        $this->info("Synchronized {$processed} pending spot changes.");

        return self::SUCCESS;
    }
}
