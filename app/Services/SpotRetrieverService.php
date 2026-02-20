<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Models\UsenetState;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use Illuminate\Support\Facades\Log;

class SpotRetrieverService
{
    protected ?NntpDriverInterface $nntp = null;

    protected bool $shuttingDown = false;

    /**
     * When true, only XOVER is fetched — HEAD enrichment and signature
     * verification are skipped. Intended for the first full index run.
     */
    protected bool $initialScan = false;

    public function __construct(
        private readonly SpotParser $parser,
        private readonly NntpService $nntpService,
        private readonly SigningService $signer,
    ) {}

    /**
     * @param  callable(int $batchStart, int $batchEnd, int $processed, int $parsed, int $inserted): void  $onBatchComplete
     */
    public function retrieve(bool $backfill = false, bool $resetBackfill = false, bool $initialScan = false, ?int $limit = null, ?int $connections = null, ?callable $onBatchComplete = null): array
    {
        $this->initialScan = $initialScan;

        $config = $this->nntpService->getConfig();
        $newsgroup = $config['groups']['spots'];
        $numConns = $connections ?? $config['connections'];

        log_debug('Starting spot retrieval', ['newsgroup' => $newsgroup, 'numConns' => $numConns, 'limit' => $limit, 'backfill' => $backfill, 'initialScan' => $initialScan]);

        // Regular incremental scans use a single connection — only a few hundred
        // articles at most, so parallel connections add overhead with no benefit.
        // Initial scans use the parallel driver so XOVER can fan out across many
        // connections for fast bulk indexing.
        $this->nntp = $initialScan
            ? $this->nntpService->makeDriver($numConns)
            : $this->nntpService->makeDriver(driver: 'single');
        $this->nntp->connect();

        echo "Selecting newsgroup {$newsgroup}... ";
        flush();
        $groupInfo = $this->nntp->group($newsgroup);
        echo "done (articles {$groupInfo['first']}–{$groupInfo['last']})\n";

        $state = UsenetState::forNewsgroup($newsgroup);

        if ($backfill) {
            if ($state->last_article_id <= 0) {
                log_debug('Nothing to backfill - run a forward retrieval first');
                $this->nntp->quit();

                return ['processed' => 0, 'inserted' => 0, 'last_article' => 0];
            }

            if ($resetBackfill) {
                $state->last_backfilled_article_id = 0;
                $state->save();
                log_debug('Backfill progress reset');
            }

            $serverFirst = $groupInfo['first'];
            $endArticle = $state->last_backfilled_article_id === 0
                ? $state->last_article_id - 1
                : $state->last_backfilled_article_id - 1;
            $startArticle = $serverFirst;

            if ($limit !== null) {
                $startArticle = max($startArticle, $endArticle - $limit + 1);
            }

            if ($startArticle > $endArticle) {
                log_debug('Backfill complete', ['last_backfilled' => $state->last_backfilled_article_id, 'server_first' => $serverFirst]);
                $this->nntp->quit();

                return ['processed' => 0, 'inserted' => 0, 'last_article' => $state->last_article_id];
            }
        } elseif ($state->last_article_id === 0) {
            $endArticle = $groupInfo['last'];
            $startArticle = $limit !== null
                ? max($groupInfo['first'], $groupInfo['last'] - $limit)
                : $groupInfo['first'];
        } else {
            $startArticle = $state->last_article_id + 1;
            $endArticle = $limit !== null
                ? min($startArticle + $limit - 1, $groupInfo['last'])
                : $groupInfo['last'];

            if ($startArticle > $groupInfo['last']) {
                log_debug('Already up to date', ['last' => $state->last_article_id]);
                $this->nntp->quit();

                return ['processed' => 0, 'inserted' => 0, 'last_article' => $state->last_article_id];
            }
        }

        $batchSize = min(config('spotengine.retrieval.batch_size', 20000), 20000);
        $forwardNewToOld = config('spotengine.retrieval.forward_new_to_old', false);
        $batches = $this->buildBatches($startArticle, $endArticle, $batchSize, $backfill, $forwardNewToOld);

        $saveStateOnlyAfterLastBatch = false;

        [
            'totalProcessed' => $totalProcessed,
            'totalInserted' => $totalInserted,
            'highestArticle' => $highestArticle,
        ] = $this->runBatches($batches, $backfill, $groupInfo, $state, $startArticle, $onBatchComplete, $saveStateOnlyAfterLastBatch);

        $this->nntp->quit();
        $this->nntp = null;

        log_debug('Retrieval complete', ['totalProcessed' => $totalProcessed, 'totalInserted' => $totalInserted, 'lastArticle' => $highestArticle, 'backfill' => $backfill]);

        return ['processed' => $totalProcessed, 'inserted' => $totalInserted, 'last_article' => $highestArticle];
    }

    /**
     * Build batch ranges [start, end] for the given article range.
     *
     * @return array<int, array{int, int}>
     */
    protected function buildBatches(int $startArticle, int $endArticle, int $batchSize, bool $backfill, bool $forwardNewToOld): array
    {
        $batches = [];

        if ($backfill || $forwardNewToOld) {
            for ($bEnd = $endArticle; $bEnd >= $startArticle; $bEnd -= $batchSize) {
                $bStart = max($bEnd - $batchSize + 1, $startArticle);
                $batches[] = [$bStart, $bEnd];
            }
        } else {
            for ($bStart = $startArticle; $bStart <= $endArticle; $bStart += $batchSize) {
                $bEnd = min($bStart + $batchSize - 1, $endArticle);
                $batches[] = [$bStart, $bEnd];
            }
        }

        return $batches;
    }

    /**
     * Execute the batch loop. Subclasses may override to change execution
     * strategy (e.g. overlapping DB upserts with the next NNTP fetch).
     *
     * @param  array<int, array{int, int}>  $batches
     * @param  array{count: int, first: int, last: int, group: string}  $groupInfo
     * @return array{totalProcessed: int, totalInserted: int, highestArticle: int}
     */
    protected function runBatches(array $batches, bool $backfill, array $groupInfo, UsenetState $state, int $startArticle, ?callable $onBatchComplete, bool $saveStateOnlyAfterLastBatch = false): array
    {
        $totalProcessed = 0;
        $totalInserted = 0;
        $highestArticle = $backfill ? 0 : ($startArticle - 1);

        foreach ($batches as [$batchStart, $batchEnd]) {
            if ($this->shuttingDown) {
                break;
            }

            [$processed, $parsed, $inserted, $lastInBatch] = $this->processBatch($batchStart, $batchEnd);

            $totalProcessed += $processed;
            $totalInserted += $inserted;
            $highestArticle = max($highestArticle, $lastInBatch);

            if ($onBatchComplete !== null) {
                $onBatchComplete($batchStart, $batchEnd, $processed, $parsed, $inserted);
            }

            if (! $saveStateOnlyAfterLastBatch) {
                $this->saveState($state, $backfill, $batchStart, $highestArticle, $groupInfo['first']);
            }
        }

        if ($saveStateOnlyAfterLastBatch && ! $this->shuttingDown) {
            $this->saveState($state, false, $startArticle, $highestArticle, $groupInfo['first']);
        }

        return compact('totalProcessed', 'totalInserted', 'highestArticle');
    }

    /** Persist the checkpoint after a completed batch. */
    protected function saveState(UsenetState $state, bool $backfill, int $batchStart, int $highestArticle, int $groupFirst): void
    {
        if ($backfill) {
            $state->fill(['last_backfilled_article_id' => $batchStart, 'last_retrieval_at' => now()]);
        } else {
            $state->fill(['last_article_id' => $highestArticle, 'first_article_id' => $groupFirst, 'last_retrieval_at' => now()]);
        }

        $state->save();
    }

    /** @return array{int, int, int, int} [processed, parsed, inserted, lastArticle] */
    private function processBatch(int $batchStart, int $batchEnd): array
    {
        [$processed, $spots, $moderationCommands, $lastInBatch] = $this->fetchBatch($batchStart, $batchEnd);
        $inserted = $this->batchUpsert($spots);
        $this->processModeration($moderationCommands);

        return [$processed, \count($spots), $inserted, $lastInBatch];
    }

    /**
     * Fetch a batch of articles via XOVER, optionally enriching with HEAD.
     *
     * In normal mode (not initial-scan), HEAD is fetched in parallel for every
     * article so the spot is fully populated (description, nzb_segments,
     * image_segment, is_verified) before it reaches the database.
     *
     * In initial-scan mode only XOVER runs — HEAD is skipped for speed.
     *
     * @return array{int, list<array<string, mixed>>, list<array<string, mixed>>, int}
     *                                                                                 [processed, spots, moderationCommands, lastArticle]
     */
    protected function fetchBatch(int $batchStart, int $batchEnd): array
    {
        $overview = $this->nntp->xover($batchStart, $batchEnd);

        $spots = [];
        $moderationCommands = [];
        $articleNumberToSpotIdx = [];

        foreach ($overview as $articleNum => $headers) {
            $result = $this->parser->parseFromOverview($headers);

            if ($result === null) {
                continue;
            }

            if (isset($result['_moderation'])) {
                $moderationCommands[] = $result;

                continue;
            }

            $articleNumberToSpotIdx[$articleNum] = \count($spots);
            $spots[] = $result;
        }

        if (! $this->initialScan && $articleNumberToSpotIdx !== []) {
            $spots = $this->enrichWithHead($spots, $articleNumberToSpotIdx);
        }

        return [\count($overview), $spots, $moderationCommands, $batchEnd];
    }

    /**
     * Fetch HEAD for each article number, parse X-XML, verify signature and
     * merge the enriched fields back into the spot array.
     *
     * @param  list<array<string, mixed>>  $spots
     * @param  array<int, int>  $articleNumberToSpotIdx  article_number → index in $spots
     * @return list<array<string, mixed>>
     */
    protected function enrichWithHead(array $spots, array $articleNumberToSpotIdx): array
    {
        $headResults = $this->nntp->headParallel(array_keys($articleNumberToSpotIdx), showProgress: false);

        foreach ($headResults as $articleNum => $headers) {
            if ($headers === null || ! isset($articleNumberToSpotIdx[$articleNum])) {
                continue;
            }

            $idx = $articleNumberToSpotIdx[$articleNum];
            $xmlContent = $headers['x-xml'] ?? '';
            $xmlSignature = $headers['x-xml-signature'] ?? '';
            $userKey = $headers['x-user-key'] ?? '';

            $xmlData = $this->parser->parseFromHeaders($headers);

            if ($xmlData !== null) {
                $isVerified = $xmlContent !== '' && $xmlSignature !== '' && $userKey !== ''
                    ? $this->signer->verify($xmlContent, $xmlSignature, $userKey)
                    : false;

                $spots[$idx] = array_merge($spots[$idx], [
                    'description' => $xmlData['description'] ?? null,
                    'nzb_segments' => $xmlData['nzb_segments'] ?? [],
                    'image_segment' => $xmlData['image_segment'] ?? null,
                    'website' => $xmlData['website'] ?? null,
                    'xml_signature' => $xmlData['xml_signature'] ?? null,
                    'poster_key_id' => $xmlData['poster_key_id'] ?? null,
                    'is_verified' => $isVerified,
                ]);
            }
        }

        return $spots;
    }

    /**
     * Apply moderation commands: delete or log spots that have been nuked.
     *
     * Validates that:
     * - The referenced spot exists in the database.
     * - The command is issued within 5 days (432 000 s) of the original posting.
     *
     * @param  list<array{_moderation: true, command: string, target_message_id: string, poster: string, stamp: int}>  $commands
     */
    protected function processModeration(array $commands): void
    {
        if ($commands === []) {
            return;
        }

        foreach ($commands as $cmd) {
            $targetId = $cmd['target_message_id'];

            $spot = Spot::query()->where('message_id', $targetId)->first();

            if (! ($spot instanceof Spot)) {
                Log::info('NUKE: target not in database', [
                    'command' => $cmd['command'],
                    'target_message_id' => $targetId,
                    'moderator' => $cmd['poster'],
                ]);

                continue;
            }

            $ageSeconds = $cmd['stamp'] - $spot->spot_posted_at->timestamp;

            if ($ageSeconds > 432000) {
                Log::warning('NUKE: command issued more than 5 days after posting — ignored', [
                    'command' => $cmd['command'],
                    'target_message_id' => $targetId,
                    'spot_title' => $spot->title,
                    'age_hours' => round($ageSeconds / 3600, 1),
                ]);

                continue;
            }

            Log::info('NUKE: removing spot', [
                'command' => $cmd['command'],
                'target_message_id' => $targetId,
                'spot_title' => $spot->title,
                'moderator' => $cmd['poster'],
            ]);

            $spot->delete();
        }
    }

    /** @param list<array<string, mixed>> $spots */
    protected function batchUpsert(array $spots): int
    {
        if ($spots === []) {
            return 0;
        }

        $spots = $this->deduplicateSpotsByMessageId($spots);

        if ($spots === []) {
            return 0;
        }

        $inserted = 0;
        $chunk = [];

        foreach ($spots as $spot) {
            $chunk[] = $spot;

            if (\count($chunk) === 2000) {
                $inserted += $this->upsertSpotChunk($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $inserted += $this->upsertSpotChunk($chunk);
        }

        return $inserted;
    }

    /**
     * @param  array<int, array<string, mixed>>  $spots
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSpotsByMessageId(array $spots): array
    {
        $seenMessageIds = [];

        for ($idx = \count($spots) - 1; $idx >= 0; $idx--) {
            $messageId = $spots[$idx]['message_id'] ?? null;

            if (! \is_string($messageId) || $messageId === '' || isset($seenMessageIds[$messageId])) {
                unset($spots[$idx]);

                continue;
            }

            $seenMessageIds[$messageId] = true;
        }

        return $spots;
    }

    /**
     * @param  array<int, array<string, mixed>>  $chunk
     */
    private function upsertSpotChunk(array $chunk): int
    {
        // Strip internal-only keys before writing to the database.
        $chunk = array_map(static function (array $spot): array {
            unset($spot['_moderation']);

            return $spot;
        }, $chunk);

        $timestamp = now();
        $rows = [];

        foreach ($chunk as $spot) {
            $rows[] = array_merge($spot, [
                'subcategories' => json_encode($spot['subcategories'] ?? []) ?: '[]',
                'nzb_segments' => json_encode($spot['nzb_segments'] ?? []) ?: '[]',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        // In initial-scan mode only XOVER fields are updated on conflict, so that
        // a later HEAD-enrichment run does not get overwritten by a re-scan.
        // In normal mode the full set (including X-XML) is updated on conflict.
        $updateColumns = $this->initialScan
            ? ['title', 'poster', 'category_code', 'subcategories', 'file_size', 'tag', 'spot_posted_at', 'updated_at']
            : ['title', 'poster', 'category_code', 'subcategories', 'file_size', 'tag', 'spot_posted_at', 'description', 'nzb_segments', 'image_segment', 'website', 'xml_signature', 'poster_key_id', 'is_verified', 'updated_at'];

        return Spot::upsert($rows, ['message_id'], $updateColumns);
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;

        try {
            $this->nntp?->quit();
        } catch (\Throwable) {
            // Ignore shutdown errors
        }

        $this->nntp = null;
    }
}
