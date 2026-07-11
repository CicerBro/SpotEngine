<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final readonly class NntpResponse
{
    public ResponseCode|int $code;

    public function __construct(
        int $code,
        public string $statusText,
    ) {
        $this->code = ResponseCode::tryFrom($code) ?? $code;
    }

    public function codeValue(): int
    {
        return $this->code instanceof ResponseCode ? $this->code->value : $this->code;
    }

    public function is(ResponseCode ...$codes): bool
    {
        return \in_array($this->codeValue(), array_map(
            static fn (ResponseCode $code): int => $code->value,
            $codes,
        ), true);
    }
}
