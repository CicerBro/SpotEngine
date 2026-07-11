<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpStreamInterface;

final readonly class ResourceNntpStream implements NntpStreamInterface
{
    /** @param resource $stream */
    public function __construct(private mixed $stream) {}

    public function read(int $length): string|false
    {
        return @fread($this->stream, $length);
    }

    public function write(string $data): int|false
    {
        return @fwrite($this->stream, $data);
    }

    public function metadata(): array
    {
        return stream_get_meta_data($this->stream);
    }

    public function eof(): bool
    {
        return feof($this->stream);
    }

    public function close(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
