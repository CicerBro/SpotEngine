<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use App\Services\SpotMutationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Bulk-enriches spots that were indexed via XOVER only (--initial-scan).
 *
 * Fetches the HEAD for each unenriched spot in parallel, parses the X-XML,
 * verifies the RSA signature and updates the database record. Safe to run
 * repeatedly — already-enriched spots are skipped.
 */
#[Description('Fetch full X-XML headers for spots indexed with --initial-scan')]
#[Signature('spot:enrich
                            {--connections= : Number of parallel NNTP connections (default from config)}
                            {--batch= : Articles per NNTP batch (default 500)}
                            {--limit= : Maximum number of spots to enrich in this run}')]
class EnrichSpots extends Command
{
    public function handle(
        NntpService $nntpService,
        SpotParser $parser,
        SigningService $signer,
        SpotMutationService $spotMutations,
    ): int {
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
        $deleted = 0;

        while (true) {
            $queryLimit = $limit !== null ? min($batchSize, $limit - $enriched) : $batchSize;

            /** @var \Illuminate\Database\Eloquent\Collection<int, Spot> $batch */
            $batch = Spot::query()
                ->whereNull('xml_signature')
                ->select(['id', 'message_id', 'title', 'category_code', 'spot_posted_at'])
                ->orderBy('id')
                ->limit($queryLimit)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $messageIds = $batch->pluck('message_id')->all();

            /** @var Collection<string, Spot> $spotsByMessageId */
            $spotsByMessageId = $batch->keyBy('message_id');

            $headResults = $nntp->headBatch($messageIds, showProgress: false);

            $upsertRows = [];
            $deleteIds = [];

            foreach ($headResults as $messageId => $headers) {
                /** @var Spot|null $spot */
                $spot = $spotsByMessageId->get((string) $messageId);

                if ($spot === null) {
                    continue;
                }

                if ($headers === null) {
                    $failed++;
                    Log::debug('spot:enrich HEAD failed', ['message_id' => $messageId]);

                    // Mark as attempted — HEAD failure may be transient.
                    $upsertRows[] = [
                        'id' => $spot->id,
                        'message_id' => $spot->message_id,
                        'title' => $spot->title,
                        'category_code' => $spot->category_code,
                        'spot_posted_at' => $spot->spot_posted_at,
                        'description' => null,
                        'image_segments' => '[]',
                        'nzb_segments' => '[]',
                        'website' => null,
                        'xml_signature' => '',
                        'poster_key_id' => null,
                        'is_verified' => false,
                    ];

                    continue;
                }

                $xmlContent = $headers['x-xml'] ?? '';
                $xmlSignature = $headers['x-xml-signature'] ?? '';
                $userKey = $headers['x-user-key'] ?? '';

                $parsed = $parser->parseFromHeaders($headers);

                if ($parsed === null || ($parsed['nzb_segments'] ?? []) === []) {
                    $deleted++;
                    $deleteIds[] = $spot->id;
                    Log::debug('spot:enrich no NZB data — deleting', ['message_id' => $messageId]);

                    continue;
                }

                $isVerified = $xmlContent !== '' && $xmlSignature !== '' && $userKey !== '' && $signer->verify($xmlContent, $xmlSignature, $userKey);

                $upsertRows[] = [
                    'id' => $spot->id,
                    'message_id' => $spot->message_id,
                    'title' => $spot->title,
                    'category_code' => $spot->category_code,
                    'spot_posted_at' => $spot->spot_posted_at,
                    'description' => $parsed['description'] ?? null,
                    'image_segments' => json_encode($parsed['image_segments'] ?? []) ?: '[]',
                    'nzb_segments' => json_encode($parsed['nzb_segments'] ?? []) ?: '[]',
                    'website' => $parsed['website'] ?? null,
                    'xml_signature' => $parsed['xml_signature'] ?? '',
                    'poster_key_id' => $parsed['poster_key_id'] ?? null,
                    'is_verified' => $isVerified,
                ];

                $enriched++;
            }

            if ($upsertRows !== []) {
                $spotMutations->upsert($upsertRows, ['id'], [
                    'description', 'image_segments', 'nzb_segments', 'website',
                    'xml_signature', 'poster_key_id', 'is_verified',
                ]);
            }

            if ($deleteIds !== []) {
                $spotMutations->delete($deleteIds);
            }

            $this->line("  {$enriched} enriched, {$failed} failed, {$deleted} deleted…");

            if ($limit !== null && $enriched >= $limit) {
                break;
            }

            if ($batch->count() < $batchSize) {
                break;
            }
        }

        $nntp->quit();

        $this->info("Done. Enriched: {$enriched}, failed (no HEAD): {$failed}, deleted (no NZB): {$deleted}.");

        return self::SUCCESS;
    }

    private function countUnenriched(): int
    {
        return Spot::query()
            ->whereNull('xml_signature')
            ->count();
    }
}
