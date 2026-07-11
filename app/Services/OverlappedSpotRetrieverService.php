<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UsenetState;
use Illuminate\Support\Facades\DB;

/**
 * Spot retriever that overlaps DB upserts with NNTP fetching.
 *
 * After fetchBatch() returns, a child process is forked to handle the DB upsert
 * while the parent immediately starts XOVER + headParallel for the next batch.
 * The child pipes back the inserted count so progress reporting stays accurate.
 *
 * The batch summary line for batch N is printed after batch N+1 is fetched
 * (once the child for N has finished). This is a cosmetic timing shift; all
 * counts remain accurate.
 *
 * Falls back to synchronous execution if pcntl_fork is unavailable.
 */
class OverlappedSpotRetrieverService extends SpotRetrieverService
{
    /**
     * @param  array<int, array{int, int}>  $batches
     * @param  array{count: int, first: int, last: int, group: string}  $groupInfo
     * @return array{totalProcessed: int, totalInserted: int, highestArticle: int}
     */
    #[\Override]
    protected function runBatches(array $batches, bool $backfill, array $groupInfo, UsenetState $state, int $startArticle, ?callable $onBatchComplete, bool $saveStateOnlyAfterLastBatch = false): array
    {
        if (! \function_exists('pcntl_fork')) {
            return parent::runBatches($batches, $backfill, $groupInfo, $state, $startArticle, $onBatchComplete, $saveStateOnlyAfterLastBatch);
        }

        $totalProcessed = 0;
        $totalInserted = 0;
        $highestArticle = $backfill ? 0 : ($startArticle - 1);

        $prevChildPid = null;
        $prevReadPipe = null;

        /** @var array{batchStart: int, batchEnd: int, processed: int, parsed: int, lastInBatch: int, moderationCommands: list<array<string,mixed>>}|null */
        $prevBatch = null;

        // Collect the previous child's result, update totals, save state, and report.
        $commitPrev = function (int $inserted) use (
            &$prevBatch, &$totalProcessed, &$totalInserted, &$highestArticle,
            $backfill, $groupInfo, $state, $onBatchComplete, $saveStateOnlyAfterLastBatch
        ): void {
            $totalInserted += $inserted;
            $totalProcessed += $prevBatch['processed'];
            $highestArticle = max($highestArticle, $prevBatch['lastInBatch']);

            // Moderation is always processed in the parent after the child upsert.
            $this->processModeration($prevBatch['moderationCommands']);

            if ($onBatchComplete !== null) {
                $onBatchComplete(
                    $prevBatch['batchStart'], $prevBatch['batchEnd'],
                    $prevBatch['processed'], $prevBatch['parsed'], $inserted
                );
            }

            if (! $saveStateOnlyAfterLastBatch) {
                $this->saveState($state, $backfill, $prevBatch['batchStart'], $highestArticle, $groupInfo['first']);
            }
        };

        foreach ($batches as [$batchStart, $batchEnd]) {
            if ($this->shuttingDown) {
                break;
            }

            // Fetch current batch — runs while the previous upsert child is active.
            [$processed, $spots, $moderationCommands, $lastInBatch] = $this->fetchBatch($batchStart, $batchEnd);
            $parsed = \count($spots);

            // SIGINT may have fired during fetchBatch (async signals). If shutdown was
            // triggered while headParallel was running, $this->nntp is now null.
            // Do not fork a new child in that case — just drain the previous one.
            // @phpstan-ignore if.alwaysFalse
            if ($this->shuttingDown) {
                break;
            }

            // Wait for the previous child before forking a new one.
            if ($prevChildPid !== null) {
                $inserted = $this->awaitUpsertChild($prevChildPid, $prevReadPipe);
                $prevChildPid = null;
                $prevReadPipe = null;
                $commitPrev($inserted);
            }

            // Open a pipe so the child can return the inserted count.
            [$readPipe, $writePipe] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            // Never fork with an active PDO connection. A child reconnect on an
            // inherited PostgreSQL socket can terminate the parent's session.
            DB::disconnect();
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed — upsert synchronously and continue.
                DB::reconnect();
                fclose($readPipe);
                fclose($writePipe);
                $inserted = $this->batchUpsert($spots);
                $prevBatch = ['batchStart' => $batchStart, 'batchEnd' => $batchEnd, 'processed' => $processed, 'parsed' => $parsed, 'lastInBatch' => $lastInBatch, 'moderationCommands' => $moderationCommands];
                $commitPrev($inserted);

                continue;
            }

            if ($pid === 0) {
                // Child: reset signal handlers so SIGINT/SIGTERM don't call shutdown()
                // and null out $this->nntp before we can detach().
                if (\function_exists('pcntl_signal')) {
                    pcntl_signal(SIGINT, SIG_DFL);
                    pcntl_signal(SIGTERM, SIG_DFL);
                }

                // Detach NNTP sockets so our exit does not QUIT the parent's
                // connections, then reconnect the DB to get a clean connection.
                fclose($readPipe);
                $this->nntp->detach();
                DB::reconnect();
                try {
                    $inserted = $this->batchUpsert($spots);
                    $payload = json_encode(['ok' => true, 'inserted' => $inserted], JSON_THROW_ON_ERROR);
                    fwrite($writePipe, $payload);
                    $exitCode = 0;
                } catch (\Throwable $exception) {
                    $payload = json_encode([
                        'ok' => false,
                        'error' => $exception::class.': '.$exception->getMessage(),
                    ], JSON_THROW_ON_ERROR);
                    fwrite($writePipe, $payload);
                    $exitCode = 1;
                }

                fclose($writePipe);
                exit($exitCode);
            }

            // Parent: track the child and immediately loop to the next fetch.
            DB::reconnect();
            fclose($writePipe);
            $prevChildPid = $pid;
            $prevReadPipe = $readPipe;
            $prevBatch = ['batchStart' => $batchStart, 'batchEnd' => $batchEnd, 'processed' => $processed, 'parsed' => $parsed, 'lastInBatch' => $lastInBatch, 'moderationCommands' => $moderationCommands];
        }

        // Collect the final child.
        if ($prevChildPid !== null) {
            $inserted = $this->awaitUpsertChild($prevChildPid, $prevReadPipe);
            $commitPrev($inserted);
        }

        if ($saveStateOnlyAfterLastBatch && ! $this->shuttingDown) {
            $this->saveState($state, false, $startArticle, $highestArticle, $groupInfo['first']);
        }

        return ['totalProcessed' => $totalProcessed, 'totalInserted' => $totalInserted, 'highestArticle' => $highestArticle];
    }

    /** @param resource $readPipe */
    private function awaitUpsertChild(int $childPid, mixed $readPipe): int
    {
        $waitedPid = pcntl_waitpid($childPid, $childStatus);
        $payload = stream_get_contents($readPipe);
        fclose($readPipe);

        $result = null;

        if (\is_string($payload) && $payload !== '') {
            try {
                $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
                $result = \is_array($decoded) ? $decoded : null;
            } catch (\JsonException) {
                $result = null;
            }
        }

        $completedSuccessfully = $waitedPid === $childPid
            && pcntl_wifexited($childStatus)
            && pcntl_wexitstatus($childStatus) === 0
            && ($result['ok'] ?? false) === true
            && \is_int($result['inserted'] ?? null);

        if (! $completedSuccessfully) {
            $error = \is_string($result['error'] ?? null)
                ? $result['error']
                : 'child exited without a valid success result';

            throw new \RuntimeException("Forked spot upsert failed: {$error}");
        }

        return $result['inserted'];
    }
}
