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
        private readonly SpotMutationService $spotMutations,
        private readonly SpotBanService $spotBans,
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
        $batches = $this->buildBatches($startArticle, $endArticle, $batchSize);

        try {
            [
                'totalProcessed' => $totalProcessed,
                'totalInserted' => $totalInserted,
                'highestArticle' => $highestArticle,
            ] = $this->runBatches($batches, $backfill, $groupInfo, $state, $startArticle, $onBatchComplete);
        } finally {
            $this->closeNntpConnection();
        }

        log_debug('Retrieval complete', ['totalProcessed' => $totalProcessed, 'totalInserted' => $totalInserted, 'lastArticle' => $highestArticle, 'backfill' => $backfill]);

        return ['processed' => $totalProcessed, 'inserted' => $totalInserted, 'last_article' => $highestArticle];
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;

        try {
            $this->closeNntpConnection();
        } catch (\Throwable) {
            // Ignore shutdown errors
        }
    }

    /**
     * Build batch ranges [start, end] for the given article range.
     *
     * @return array<int, array{int, int}>
     */
    protected function buildBatches(int $startArticle, int $endArticle, int $batchSize): array
    {
        $batches = [];

        for ($bStart = $startArticle; $bStart <= $endArticle; $bStart += $batchSize) {
            $bEnd = min($bStart + $batchSize - 1, $endArticle);
            $batches[] = [$bStart, $bEnd];
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
    protected function runBatches(array $batches, bool $backfill, array $groupInfo, UsenetState $state, int $startArticle, ?callable $onBatchComplete): array
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

            $this->saveState($state, $backfill, $batchStart, $highestArticle, $groupInfo['first']);
        }

        return ['totalProcessed' => $totalProcessed, 'totalInserted' => $totalInserted, 'highestArticle' => $highestArticle];
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

    /**
     * Fetch a batch of articles via XOVER, optionally enriching with HEAD.
     *
     * In normal mode (not initial-scan), HEAD is fetched in parallel for every
     * article so the spot is fully populated (description, nzb_segments,
     * image_segments, is_verified) before it reaches the database.
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

            if ($this->spotBans->isBanned($result['poster'] ?? null, $result['tag'] ?? null)) {
                continue;
            }

            $articleNumberToSpotIdx[$articleNum] = \count($spots);
            $spots[] = $result;
        }

        if (! $this->initialScan && $articleNumberToSpotIdx !== []) {
            $spots = $this->enrichWithHead($spots, $articleNumberToSpotIdx);
        }

        $spots = array_values(array_filter(
            $spots,
            fn (array $spot): bool => ! $this->spotBans->isBanned(
                $spot['poster'] ?? null,
                $spot['tag'] ?? null,
                $spot['poster_key_id'] ?? null,
            ),
        ));

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
        $headResults = $this->nntp->headBatch(array_keys($articleNumberToSpotIdx), showProgress: false);

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
                $isVerified = $xmlContent !== '' && $xmlSignature !== '' && $userKey !== '' && $this->signer->verify($xmlContent, $xmlSignature, $userKey);

                $spots[$idx] = array_merge($spots[$idx], [
                    'description' => $xmlData['description'] ?? null,
                    'image_segments' => $xmlData['image_segments'] ?? [],
                    'nzb_segments' => $xmlData['nzb_segments'] ?? [],
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
     * @param  list<array{_moderation: true, command: string, target_message_id: string, poster: string, stamp: int, is_global_moderator: bool, moderator_key_id: string|null}>  $commands
     */
    protected function processModeration(array $commands): void
    {
        if ($commands === []) {
            return;
        }

        $targetIds = array_column($commands, 'target_message_id');
        $spots = Spot::query()
            ->whereIn('message_id', $targetIds)
            ->get()
            ->keyBy('message_id');

        $deleteIds = [];

        foreach ($commands as $cmd) {
            $targetId = $cmd['target_message_id'];
            $spot = $spots->get($targetId);

            if (! ($spot instanceof Spot)) {
                Log::info('NUKE: target not in database', [
                    'command' => $cmd['command'],
                    'target_message_id' => $targetId,
                    'moderator' => $cmd['poster'],
                ]);

                continue;
            }

            $isAuthorized = $cmd['is_global_moderator']
                || (
                    $cmd['moderator_key_id'] !== null
                    && $cmd['moderator_key_id'] !== ''
                    && $spot->is_verified
                    && hash_equals((string) $spot->poster_key_id, $cmd['moderator_key_id'])
                );

            if (! $isAuthorized) {
                Log::warning('NUKE: authenticated personal dispose does not match spot owner — ignored', [
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

            $deleteIds[] = $spot->id;
        }

        if ($deleteIds !== []) {
            $this->spotMutations->delete($deleteIds);
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

    /** @return array{int, int, int, int} [processed, parsed, inserted, lastArticle] */
    private function processBatch(int $batchStart, int $batchEnd): array
    {
        [$processed, $spots, $moderationCommands, $lastInBatch] = $this->fetchBatch($batchStart, $batchEnd);
        $inserted = $this->batchUpsert($spots);
        $this->processModeration($moderationCommands);

        return [$processed, \count($spots), $inserted, $lastInBatch];
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
            if ($this->initialScan) {
                $rows[] = [
                    'message_id' => $spot['message_id'],
                    'poster' => $spot['poster'] ?? null,
                    'title' => $spot['title'],
                    'tag' => $spot['tag'] ?? null,
                    'category_code' => $spot['category_code'],
                    'subcategories' => json_encode($spot['subcategories'] ?? []) ?: '[]',
                    'file_size' => $spot['file_size'] ?? 0,
                    'spot_posted_at' => $spot['spot_posted_at'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                continue;
            }

            $rows[] = array_merge($spot, [
                'subcategories' => json_encode($spot['subcategories'] ?? []) ?: '[]',
                'nzb_segments' => json_encode($spot['nzb_segments'] ?? []) ?: '[]',
                'image_segments' => json_encode($spot['image_segments'] ?? []) ?: '[]',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        if ($this->initialScan) {
            return $this->spotMutations->insertOrIgnore($rows);
        }

        $updateColumns = ['title', 'poster', 'category_code', 'subcategories', 'file_size', 'tag', 'spot_posted_at', 'description', 'image_segments', 'nzb_segments', 'website', 'xml_signature', 'poster_key_id', 'is_verified', 'updated_at'];

        return $this->spotMutations->upsert($rows, ['message_id'], $updateColumns);
    }

    private function closeNntpConnection(): void
    {
        if (! $this->nntp instanceof NntpDriverInterface) {
            return;
        }

        $nntp = $this->nntp;
        $this->nntp = null;
        $nntp->quit();
    }
}
