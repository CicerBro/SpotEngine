<?php

declare(strict_types=1);

namespace App\Services\Nntp\Contracts;

use App\Services\Nntp\HeadBatchResult;

interface NntpDriverInterface
{
    public function connect(bool $showProgress = true): void;

    /** @return array{count: int, first: int, last: int, group: string} */
    public function group(string $groupName): array;

    /** @return array<int, array{subject: string, from: string, date: string, message_id: string}> */
    public function xover(int $start, int $end): array;

    /**
     * @param  array<int|string>  $articles  Article numbers (int) or message-IDs (string, without angle brackets)
     * @param  callable(int|string, HeadBatchResult): void|null  $onArticle
     * @return array<int|string, array<string, string>|null>
     *
     * Without a callback, this retains the legacy headers-or-null return value.
     * Streaming callbacks receive a typed result so callers can distinguish a
     * missing article from a timeout after one fresh-connection retry. Fatal NNTP
     * failures throw and do not invoke the callback for pending articles.
     */
    public function headBatch(array $articles, bool $showProgress = true, ?callable $onArticle = null): array;

    public function quit(): void;

    /**
     * Close all socket file descriptors without sending QUIT.
     * Call this in a forked child process to prevent the child's destructors
     * from terminating the parent's open connections.
     */
    public function detach(): void;

    public function getConnectionCount(): int;
}
