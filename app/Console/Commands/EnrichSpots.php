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
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Bulk-enriches spots that were indexed via XOVER only (--initial-scan).
 *
 * Fetches the HEAD for each unenriched spot in parallel, parses the X-XML,
 * verifies the RSA signature and updates the database record. Safe to run
 * repeatedly — already-enriched spots are skipped.
 *
 * A lot of old spots won't have NZB data, so they'll be deleted.
 */
#[Description('Fetch full X-XML headers for spots indexed with --initial-scan. Oldest spots first by default, use --desc to process newest first.')]
#[Signature('spot:enrich
                            {--connections= : Number of parallel NNTP connections (default from config)}
                            {--batch= : Articles per NNTP batch (default 500)}
                            {--limit= : Maximum number of spots to enrich in this run}
                            {--desc : Process spots in descending posted-date order}')]
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
        $orderDescending = (bool) $this->option('desc');

        $total = $this->countUnenriched();

        if ($total === 0) {
            $this->info('All spots are already enriched.');

            return self::SUCCESS;
        }

        $cap = $limit !== null ? min($total, $limit) : $total;
        $this->info("Enriching {$cap} of {$total} unenriched spots using {$connections} connections…");

        $nntp = $nntpService->makeDriver($connections);
        $nntp->connect();

        $attempted = 0;
        $enriched = 0;
        $failed = 0;
        $deleted = 0;

        $progressBar = $this->createEnrichProgressBar($cap, $enriched, $failed, $deleted);
        $progressBar->start();

        while (true) {
            $queryLimit = $limit !== null ? min($batchSize, $limit - $attempted) : $batchSize;

            if ($queryLimit <= 0) {
                break;
            }

            $query = Spot::query()
                ->whereNull('xml_signature')
                ->select(['id', 'message_id', 'title', 'category_code', 'spot_posted_at']);

            if ($orderDescending) {
                $query->orderByDesc('spot_posted_at')
                    ->orderByDesc('id');
            } else {
                $query->orderBy('spot_posted_at')
                    ->orderBy('id');
            }

            /** @var \Illuminate\Database\Eloquent\Collection<int, Spot> $batch */
            $batch = $query
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

                $attempted++;

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
                    Log::debug('spot:enrich not fully indexable — deleting', [
                        'message_id' => $messageId,
                        'reason' => $parsed === null ? 'xml_parse_failed' : 'missing_nzb_segments',
                    ]);

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

            $progressBar->setProgress($attempted);
            $progressBar->display();

            if ($limit !== null && $attempted >= $limit) {
                break;
            }

            if ($batch->count() < $batchSize) {
                break;
            }
        }

        $nntp->quit();

        $progressBar->finish();
        $this->newLine();

        $this->info("Done. Enriched: {$enriched}, failed (no HEAD): {$failed}, deleted (not fully indexable): {$deleted}.");

        return self::SUCCESS;
    }

    private function countUnenriched(): int
    {
        return Spot::query()
            ->whereNull('xml_signature')
            ->count();
    }

    private function createEnrichProgressBar(
        int $max,
        int &$enriched,
        int &$failed,
        int &$deleted,
    ): ProgressBar {
        $bar = $this->output->createProgressBar($max);
        $bar->setBarCharacter('█');
        $bar->setEmptyBarCharacter('░');
        $bar->setProgressCharacter('█');
        $bar->setFormat(
            ' %current%/%max% [%bar%] %percent:3s%%  enriched: %enriched%  failed: %failed%  deleted: %deleted%',
        );
        $bar->setPlaceholderFormatterDefinition('enriched', function () use (&$enriched): string {
            return (string) $enriched;
        });
        $bar->setPlaceholderFormatterDefinition('failed', function () use (&$failed): string {
            return (string) $failed;
        });
        $bar->setPlaceholderFormatterDefinition('deleted', function () use (&$deleted): string {
            return (string) $deleted;
        });

        return $bar;
    }
}
