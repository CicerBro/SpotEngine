<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final readonly class HeadBatchResult
{
    /** @param array<string, string>|null $headers */
    private function __construct(
        public HeadBatchOutcome $outcome,
        public ?array $headers = null,
    ) {}

    /** @param array<string, string> $headers */
    public static function success(array $headers): self
    {
        return new self(HeadBatchOutcome::Success, $headers);
    }

    public static function missing(): self
    {
        return new self(HeadBatchOutcome::Missing);
    }

    public static function timedOutAfterRetry(): self
    {
        return new self(HeadBatchOutcome::TimedOutAfterRetry);
    }

    public function isEligibleForDeletion(): bool
    {
        return $this->outcome === HeadBatchOutcome::Missing
            || $this->outcome === HeadBatchOutcome::TimedOutAfterRetry;
    }
}
