<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Search\SearchIndexSynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('spot:search-rebuild {--batch=1000 : Documents per Manticore request} {--check : Check database/index parity without rebuilding}')]
#[Description('Rebuild or reconcile the complete Manticore spot index')]
class RebuildSearchIndex extends Command
{
    public function handle(SearchIndexSynchronizer $synchronizer): int
    {
        $batchSize = max(1, (int) $this->option('batch'));

        try {
            $result = Cache::lock('spot-search-index-maintenance', 3600)->get(
                function () use ($synchronizer, $batchSize): array {
                    $indexed = null;

                    if (! $this->option('check')) {
                        $this->info('Rebuilding the Manticore spot index…');
                        $indexed = $synchronizer->rebuild($batchSize);
                    }

                    return [
                        'indexed' => $indexed,
                        'parity' => $synchronizer->parity($batchSize),
                        'pending' => $synchronizer->pendingCount(),
                    ];
                },
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result === false) {
            $this->warn('Another search index maintenance command is already running.');

            return self::FAILURE;
        }

        if ($result['indexed'] !== null) {
            $this->info("Indexed {$result['indexed']} spots.");
        }

        $this->table(['Database', 'Manticore', 'Missing', 'Pending'], [[
            $result['parity']['database'],
            $result['parity']['index'],
            $result['parity']['missing'],
            $result['pending'],
        ]]);

        $isSynchronized = $result['parity']['database'] === $result['parity']['index']
            && $result['parity']['missing'] === 0
            && $result['pending'] === 0;

        if (! $isSynchronized) {
            $this->error('Search index parity check failed.');

            return self::FAILURE;
        }

        $this->info('Search index parity verified.');

        return self::SUCCESS;
    }
}
