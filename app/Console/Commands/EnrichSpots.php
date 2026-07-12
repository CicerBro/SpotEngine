<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Spot;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\HeadBatchResult;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use App\Services\SpotMutationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Bulk-enriches spots that were indexed via XOVER only (--initial-scan).
 *
 * Fetches the HEAD for each unenriched spot in parallel, parses the X-XML,
 * verifies the RSA signature and updates the database record. Safe to run
 * repeatedly — already-enriched spots are skipped.
 *
 * Spots are processed newest-first (highest primary key first) so recent
 * content becomes fully browsable quickly. Very old spots can take a long
 * time to receive an NNTP HEAD reply from the server.
 *
 * A lot of old spots won't have NZB data, so they'll be deleted.
 */
#[Description('Fetch full X-XML headers for spots indexed with --initial-scan, newest first by primary key.')]
#[Signature('spot:enrich
                            {--connections= : Number of parallel NNTP connections (default from config)}
                            {--batch= : Articles per DB page when loading spots (default 500)}
                            {--limit= : Maximum number of spots to enrich in this run}')]
class EnrichSpots extends Command
{
    private const int HEAD_STREAM_SIZE = 5000;

    private const int FLUSH_SIZE = 500;

    private bool $shouldStop = false;

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
        $this->line('  Processing newest spots first. Very old spots can take a long time for an NNTP HEAD reply.');
        $this->newLine();

        $nntp = $nntpService->makeDriver($connections);
        $nntp->connect();
        $nntp->group((string) $config['groups']['spots']);

        $this->shouldStop = false;
        $this->registerInterruptHandler($nntp);

        $attempted = 0;
        $enriched = 0;
        $deleted = 0;
        $cursorId = null;

        $progressBar = $this->createEnrichProgressBar($cap, $enriched, $deleted);
        $progressBar->start();

        try {
            while (true) {
                if ($this->wasInterrupted()) {
                    break;
                }

                $remaining = $limit !== null ? $limit - $attempted : null;

                if ($remaining !== null && $remaining <= 0) {
                    break;
                }

                $streamLimit = $remaining !== null ? min(self::HEAD_STREAM_SIZE, $remaining) : self::HEAD_STREAM_SIZE;

                /** @var Collection<int, Spot> $streamBatch */
                $streamBatch = new Collection;

                while ($streamBatch->count() < $streamLimit) {
                    $pageLimit = min($batchSize, $streamLimit - $streamBatch->count());

                    $query = Spot::query()
                        ->whereNull('xml_signature')
                        ->select(['id', 'message_id', 'title', 'category_code', 'spot_posted_at']);

                    if ($cursorId !== null) {
                        $query->where('id', '<', $cursorId);
                    }

                    /** @var Collection<int, Spot> $page */
                    $page = $query
                        ->orderByDesc('id')
                        ->limit($pageLimit)
                        ->get();

                    if ($page->isEmpty()) {
                        break;
                    }

                    $streamBatch = $streamBatch->concat($page);
                    $cursorId = $page->last()->id;

                    if ($page->count() < $pageLimit) {
                        break;
                    }
                }

                if ($streamBatch->isEmpty()) {
                    break;
                }

                /** @var array<string, Spot> $spotsByMessageId */
                $spotsByMessageId = $streamBatch->keyBy('message_id')->all();

                $messageIds = $streamBatch->pluck('message_id')->all();

                $upsertRows = [];
                $deleteIds = [];
                $completionsSinceFlush = 0;

                $flush = function () use (
                    &$upsertRows,
                    &$deleteIds,
                    &$completionsSinceFlush,
                    $spotMutations,
                    $progressBar,
                    &$attempted,
                ): void {
                    if ($upsertRows !== []) {
                        $spotMutations->upsert($upsertRows, ['id'], [
                            'description', 'image_segments', 'nzb_segments', 'website',
                            'xml_signature', 'poster_key_id', 'is_verified',
                        ]);
                        $upsertRows = [];
                    }

                    if ($deleteIds !== []) {
                        $spotMutations->delete($deleteIds);
                        $deleteIds = [];
                    }

                    $completionsSinceFlush = 0;
                    $progressBar->setProgress($attempted);
                    $progressBar->display();
                };

                $processArticle = function (int|string $messageId, HeadBatchResult $result) use (
                    &$spotsByMessageId,
                    &$upsertRows,
                    &$deleteIds,
                    &$completionsSinceFlush,
                    &$attempted,
                    &$enriched,
                    &$deleted,
                    $parser,
                    $signer,
                    $flush,
                ): void {
                    $spot = $spotsByMessageId[(string) $messageId] ?? null;

                    if ($spot === null) {
                        return;
                    }

                    unset($spotsByMessageId[(string) $messageId]);
                    $attempted++;

                    if ($result->isEligibleForDeletion()) {
                        $deleted++;
                        $deleteIds[] = $spot->id;
                        Log::debug('spot:enrich definitive HEAD failure — deleting', [
                            'message_id' => $spot->message_id,
                            'outcome' => $result->outcome->name,
                        ]);

                        $completionsSinceFlush++;

                        if ($completionsSinceFlush >= self::FLUSH_SIZE) {
                            $flush();
                        }

                        return;
                    }

                    $headers = $result->headers;

                    if ($headers === null) {
                        throw new \LogicException('Successful NNTP HEAD result did not include headers.');
                    }

                    $xmlContent = $headers['x-xml'] ?? '';
                    $xmlSignature = $headers['x-xml-signature'] ?? '';
                    $userKey = $headers['x-user-key'] ?? '';

                    $parsed = $parser->parseFromHeaders($headers);

                    if ($parsed === null || ($parsed['nzb_segments'] ?? []) === []) {
                        $deleted++;
                        $deleteIds[] = $spot->id;
                        Log::debug('spot:enrich not fully indexable — deleting', [
                            'message_id' => $spot->message_id,
                            'reason' => $parsed === null ? 'xml_parse_failed' : 'missing_nzb_segments',
                        ]);

                        $completionsSinceFlush++;

                        if ($completionsSinceFlush >= self::FLUSH_SIZE) {
                            $flush();
                        }

                        return;
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
                    $completionsSinceFlush++;

                    if ($completionsSinceFlush >= self::FLUSH_SIZE) {
                        $flush();
                    }
                };

                try {
                    $nntp->headBatch($messageIds, showProgress: false, onArticle: $processArticle);
                } catch (NntpException $exception) {
                    if ($upsertRows !== [] || $deleteIds !== []) {
                        $flush();
                    }

                    $this->error("NNTP HEAD batch aborted; unattempted spots were preserved: {$exception->getMessage()}");

                    return self::FAILURE;
                }

                if ($upsertRows !== [] || $deleteIds !== []) {
                    $flush();
                }

                if ($this->wasInterrupted()) {
                    break;
                }

                if ($limit !== null && $attempted >= $limit) {
                    break;
                }

                if ($streamBatch->count() < $streamLimit) {
                    break;
                }
            }
        } finally {
            try {
                $nntp->quit();
            } catch (\Throwable) {
                // Ignore quit errors during shutdown.
            }

            $progressBar->finish();
            $this->newLine();
        }

        if ($this->wasInterrupted()) {
            $this->warn("Stopped early. Enriched: {$enriched}, deleted (unusable or no HEAD): {$deleted}.");

            return self::SUCCESS;
        }

        $this->info("Done. Enriched: {$enriched}, deleted (unusable or no HEAD): {$deleted}.");

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
        int &$deleted,
    ): ProgressBar {
        $bar = $this->output->createProgressBar($max);
        $bar->setBarCharacter('█');
        $bar->setEmptyBarCharacter('░');
        $bar->setProgressCharacter('█');
        $bar->setFormat(
            ' %current%/%max% [%bar%] %percent:3s%%  enriched: %enriched%  deleted: %deleted%',
        );
        $bar->setPlaceholderFormatterDefinition('enriched', function () use (&$enriched): string {
            return (string) $enriched;
        });
        $bar->setPlaceholderFormatterDefinition('deleted', function () use (&$deleted): string {
            return (string) $deleted;
        });

        return $bar;
    }

    /**
     * Signal handlers may flip this during long-running NNTP calls.
     *
     * @phpstan-impure
     */
    private function wasInterrupted(): bool
    {
        return $this->shouldStop;
    }

    private function registerInterruptHandler(NntpDriverInterface $nntp): void
    {
        if (! function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function () use ($nntp): void {
            $this->shouldStop = true;
            $this->newLine();
            $this->warn('Interrupted — closing NNTP connections…');

            try {
                $nntp->quit();
            } catch (\Throwable) {
                // Ignore quit errors during shutdown.
            }
        };

        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }
}
