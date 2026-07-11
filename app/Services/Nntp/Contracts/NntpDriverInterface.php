<?php

declare(strict_types=1);

namespace App\Services\Nntp\Contracts;

interface NntpDriverInterface
{
    public function connect(bool $showProgress = true): void;

    /** @return array{count: int, first: int, last: int, group: string} */
    public function group(string $groupName): array;

    /** @return array<int, array{subject: string, from: string, date: string, message_id: string}> */
    public function xover(int $start, int $end): array;

    /**
     * @param  array<int|string>  $articles  Article numbers (int) or message-IDs (string, without angle brackets)
     * @param  callable(?array<string,string>): void|null  $onArticle
     * @return array<int|string, array<string, string>|null>
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
