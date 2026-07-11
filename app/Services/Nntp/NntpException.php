<?php

declare(strict_types=1);

namespace App\Services\Nntp;

class NntpException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $responseCode = null,
        public readonly ?string $statusText = null,
        public readonly ?string $operation = null,
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $responseCode ?? 0, $previous);
    }

    public static function unexpected(string $operation, NntpResponse $response): self
    {
        $code = $response->codeValue();
        $knownCode = ResponseCode::tryFrom($code);
        $description = $knownCode?->description() ?? 'Unexpected NNTP response';

        return new self(
            "{$operation} failed with {$code}: {$description} ({$response->statusText})",
            $code,
            $response->statusText,
            $operation,
        );
    }
}
