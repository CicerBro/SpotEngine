<?php

declare(strict_types=1);

namespace App\Services\Nntp\Contracts;

interface NntpStreamInterface
{
    public function read(int $length): string|false;

    public function write(string $data): int|false;

    /** @return array<string, mixed> */
    public function metadata(): array;

    public function eof(): bool;

    public function close(): void;
}
