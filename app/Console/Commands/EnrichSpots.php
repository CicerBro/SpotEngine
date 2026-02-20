<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Bulk-enriches spots that were indexed via XOVER only (--initial-scan).
 *
 * Fetches the HEAD for each unenriched spot in parallel, parses the X-XML,
 * verifies the RSA signature and updates the database record. Safe to run
 * repeatedly — already-enriched spots are skipped.
 */
class EnrichSpots extends Command
{
    protected $signature = 'spot:enrich
                            {--connections= : Number of parallel NNTP connections (default from config)}
                            {--batch= : Articles per NNTP batch (default 500)}
                            {--limit= : Maximum number of spots to enrich in this run}';

    protected $description = 'Fetch full X-XML headers for spots indexed with --initial-scan';

    public function handle(NntpService $nntpService, SpotParser $parser, SigningService $signer): int
    {
        $config = $nntpService->getConfig();
        $connections = $this->option('connections') !== null
            ? (int) $this->option('connections')
            : (int) $config['connections'];
        $batchSize = $this->option('batch') !== null ? (int) $this->option('batch') : 500;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $total = $this->countUnenriched();

        if ($total === 0) {
            $this->info('All spots are already enriched.');

            return self::SUCCESS;
        }

        $cap = $limit !== null ? min($total, $limit) : $total;
        $this->info("Enriching {$cap} of {$total} unenriched spots using {$connections} connections…");

        $nntp = $nntpService->makeDriver($connections);
        $nntp->connect();

        $enriched = 0;
        $failed = 0;

        while (true) {
            $queryLimit = $limit !== null ? min($batchSize, $limit - $enriched) : $batchSize;

            /** @var \Illuminate\Database\Eloquent\Collection<int, Spot> $batch */
            $batch = Spot::query()
                ->whereNull('xml_signature')
                ->select(['id', 'message_id'])
                ->orderBy('id')
                ->limit($queryLimit)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $messageIds = $batch->pluck('message_id')->all();

            /** @var \Illuminate\Support\Collection<string, Spot> $spotsByMessageId */
            $spotsByMessageId = $batch->keyBy('message_id');

            $headResults = $nntp->headParallel($messageIds, showProgress: false);

            foreach ($headResults as $messageId => $headers) {
                /** @var Spot|null $spot */
                $spot = $spotsByMessageId->get((string) $messageId);

                if ($spot === null) {
                    continue;
                }

                if ($headers === null) {
                    $failed++;
                    Log::debug('spot:enrich HEAD failed', ['message_id' => $messageId]);

                    // Mark as attempted so this spot isn't retried forever.
                    $spot->update(['xml_signature' => '']);

                    continue;
                }

                $xmlContent = $headers['x-xml'] ?? '';
                $xmlSignature = $headers['x-xml-signature'] ?? '';
                $userKey = $headers['x-user-key'] ?? '';

                $parsed = $parser->parseFromHeaders($headers);

                $isVerified = $xmlContent !== '' && $xmlSignature !== '' && $userKey !== ''
                    ? $signer->verify($xmlContent, $xmlSignature, $userKey)
                    : false;

                $spot->update([
                    'description' => $parsed['description'] ?? null,
                    'nzb_segments' => $parsed['nzb_segments'] ?? [],
                    'image_segment' => $parsed['image_segment'] ?? null,
                    'website' => $parsed['website'] ?? null,
                    'xml_signature' => $parsed['xml_signature'] ?? '',
                    'poster_key_id' => $parsed['poster_key_id'] ?? null,
                    'is_verified' => $isVerified,
                ]);

                $enriched++;
            }

            $this->line("  {$enriched} enriched, {$failed} failed…");

            if ($limit !== null && $enriched >= $limit) {
                break;
            }

            if ($batch->count() < $batchSize) {
                break;
            }
        }

        $nntp->quit();

        $this->info("Done. Enriched: {$enriched}, failed (no HEAD): {$failed}.");

        return self::SUCCESS;
    }

    private function countUnenriched(): int
    {
        return Spot::query()
            ->whereNull('xml_signature')
            ->count();
    }
}
