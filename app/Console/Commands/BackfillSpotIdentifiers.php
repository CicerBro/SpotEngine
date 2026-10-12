<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Spot;
use App\Services\SpotMutationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spot:identifiers-backfill {--batch=500 : Rows per transaction (1-1000)} {--limit= : Maximum rows for this invocation}')]
#[Description('Normalize external identifiers in bounded batches; restart skips completed rows')]
class BackfillSpotIdentifiers extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SpotMutationService $mutations): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $limitOption = $this->option('limit');
        $limit = $limitOption === null ? null : filter_var($limitOption, FILTER_VALIDATE_INT);

        if ($batch === false || $batch < 1 || $batch > 1000 || ($limit !== null && ($limit === false || $limit < 1))) {
            $this->error('Batch must be 1-1000 and limit must be a positive integer.');

            return self::FAILURE;
        }

        $processed = 0;

        while ($limit === null || $processed < $limit) {
            $ids = Spot::query()->whereNull('external_identifiers')->orderBy('id')
                ->limit($limit === null ? $batch : min($batch, $limit - $processed))
                ->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $mutations->refreshIdentifiers($ids);
            $processed += count($ids);
            $this->line("Normalized {$processed} spots.");
        }

        return self::SUCCESS;
    }
}
