<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Models\UsenetState;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SpotParser;
use Illuminate\Support\Facades\Log;

class SpotRetrieverService
{
    protected ?NntpDriverInterface $nntp = null;

    protected bool $shuttingDown = false;

    public function __construct(
        private readonly SpotParser $parser,
        private readonly NntpService $nntpService,
    ) {}

    /**
     * @param  callable(int $batchStart, int $batchEnd, int $processed, int $parsed, int $inserted): void  $onBatchComplete
     */
    public function retrieve(bool $fullRetrieval = false, bool $backfill = false, bool $resetBackfill = false, ?int $limit = null, ?int $connections = null, ?callable $onBatchComplete = null): array
    {
        $config = $this->nntpService->getConfig();
        $newsgroup = $config['groups']['spots'];
        $numConns = $connections ?? $config['connections'];

        log_debug('Starting spot retrieval', ['newsgroup' => $newsgroup, 'numConns' => $numConns, 'limit' => $limit, 'fullRetrieval' => $fullRetrieval, 'backfill' => $backfill]);

        $this->nntp = $this->nntpService->makeDriver($numConns);
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
        } elseif ($fullRetrieval || $state->last_article_id === 0) {
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

        $saveStateOnlyAfterLastBatch = ! $backfill && $forwardNewToOld;

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
        [$processed, $spots, $lastInBatch] = $this->fetchBatch($batchStart, $batchEnd);
        $inserted = $this->batchUpsert($spots);

        return [$processed, \count($spots), $inserted, $lastInBatch];
    }

    /** @return array{int, list<array<string, mixed>>, int} [processed, spots, lastArticle] */
    protected function fetchBatch(int $batchStart, int $batchEnd): array
    {
        try {
            $articles = $this->nntp->xover($batchStart, $batchEnd);
        } catch (\Throwable $e) {
            Log::error("XOVER failed for $batchStart-$batchEnd: ".$e->getMessage());

            return [0, [], $batchEnd];
        }

        if ($articles === []) {
            return [0, [], $batchEnd];
        }

        $spots = [];

        $this->nntp->headParallel(
            array_keys($articles),
            true,
            function (?array $headers) use (&$spots): void {
                if ($headers === null || ! isset($headers['x-xml'])) {
                    return;
                }

                $spot = $this->parser->parseFromHeaders($headers);

                if ($spot) {
                    $spots[] = $spot;
                }
            }
        );

        return [\count($articles), $spots, $batchEnd];
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

        return Spot::upsert(
            $rows,
            ['message_id'],
            ['title', 'description', 'subcategories', 'nzb_segments', 'file_size', 'image_segment', 'updated_at']
        );
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
