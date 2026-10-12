<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpStreamInterface;

final class NntpProtocol
{
    private const int MAX_COMMAND_LENGTH = 510;

    private const int MAX_STATUS_CONTINUATIONS = 32;

    private string $readBuffer = '';

    public function __construct(
        private readonly NntpStreamInterface $stream,
        private readonly int $readChunkSize = 65536,
    ) {}

    /** @param resource $stream */
    public static function forResource(mixed $stream, int $readChunkSize = 65536): self
    {
        return new self(new ResourceNntpStream($stream), $readChunkSize);
    }

    /**
     * @return list<string>
     */
    public static function decodeCompressedTextResponse(string $wireData): array
    {
        if (str_ends_with($wireData, ".\r\n")) {
            $wireData = substr($wireData, 0, -3);
        }

        if (strlen($wireData) > self::limit('nntp_response_bytes', 67_108_864)) {
            throw new NntpException('NNTP compressed response exceeds byte limit', operation: 'XOVER');
        }

        $decompressed = @gzuncompress($wireData, self::limit('nntp_response_bytes', 67_108_864));

        if ($decompressed === false) {
            throw new NntpException('Failed to decompress NNTP overview response', operation: 'XOVER');
        }

        return self::splitDecompressedResponse($decompressed);
    }

    public function writeCommand(string $command): void
    {
        if ($command === '' || strlen($command) > self::MAX_COMMAND_LENGTH) {
            throw new NntpException('NNTP command length is invalid', operation: 'write');
        }

        if (strpbrk($command, "\r\n") !== false) {
            throw new NntpException('NNTP command contains an illegal line break', operation: 'write');
        }

        $framedCommand = "{$command}\r\n";
        $written = 0;
        $length = strlen($framedCommand);

        while ($written < $length) {
            $bytesWritten = $this->stream->write(substr($framedCommand, $written));

            if ($bytesWritten === false || $bytesWritten === 0) {
                throw new NntpException('Failed to write complete NNTP command', operation: 'write');
            }

            $written += $bytesWritten;
        }
    }

    public function command(string $command): NntpResponse
    {
        $this->writeCommand($command);

        return $this->readResponse();
    }

    public function readResponse(): NntpResponse
    {
        $continuations = 0;

        do {
            $line = $this->readLine('status response');

            if (! preg_match('/^(\d{3})([-\s]?)(.*)$/', $line, $matches)) {
                throw new NntpException("Malformed NNTP status response: {$line}", statusText: $line, operation: 'read');
            }

            $code = (int) $matches[1];
            $continuation = $matches[2] === '-';
            $statusText = $matches[3];

            if ($continuation && ++$continuations > self::MAX_STATUS_CONTINUATIONS) {
                throw new NntpException('NNTP status response continuation exceeded limit', $code, $statusText, 'read');
            }
        } while ($continuation);

        return new NntpResponse($code, $statusText);
    }

    /** @return list<string> */
    public function readTextResponse(?int $maxBytes = null): array
    {
        $lines = [];
        $bytes = 0;
        $maxBytes ??= self::limit('nntp_response_bytes', 67_108_864);

        while (true) {
            $line = $this->readLine('multiline response', min(self::limit('nntp_line_bytes', 1_048_576), $maxBytes - $bytes + 3));

            if ($line === '.') {
                return $lines;
            }

            $line = str_starts_with($line, '..') ? substr($line, 1) : $line;
            $bytes += strlen($line) + 2;
            if ($bytes > $maxBytes || count($lines) >= self::limit('nntp_response_lines', 100_000)) {
                $this->exceededLimit('multiline response');
            }
            $lines[] = $line;
        }
    }

    public function readBodyResponse(): string
    {
        $body = '';
        $lines = 0;
        $maxBytes = self::limit('nntp_body_bytes', 16_777_216);

        while (true) {
            $line = $this->readLine('body response', min(self::limit('nntp_line_bytes', 1_048_576), $maxBytes - strlen($body) + 3));
            if ($line === '.') {
                return $body;
            }
            $line = str_starts_with($line, '..') ? substr($line, 1) : $line;
            $separator = $lines === 0 ? '' : "\r\n";
            if (strlen($line) + strlen($separator) > $maxBytes - strlen($body)
                || ++$lines > self::limit('nntp_response_lines', 100_000)) {
                $this->exceededLimit('body response');
            }
            $body .= $separator . $line;
        }
    }

    /** @return list<string> */
    public function readCompressedTextResponse(?int $maxBytes = null): array
    {
        $wireData = $this->readBuffer;
        $this->readBuffer = '';
        $foundTerminator = false;
        $maxBytes ??= self::limit('nntp_response_bytes', 67_108_864);

        while (true) {
            if (strlen($wireData) > $maxBytes) {
                $this->exceededLimit('compressed response');
            }
            if (str_ends_with($wireData, ".\r\n")) {
                $foundTerminator = true;
                $decompressed = @gzuncompress(substr($wireData, 0, -3), $maxBytes);

                if ($decompressed !== false) {
                    return self::splitDecompressedResponse($decompressed);
                }
            }

            $chunk = $this->stream->read(min($this->readChunkSize, $maxBytes - strlen($wireData) + 1));

            if ($chunk === false || $chunk === '') {
                if ($foundTerminator) {
                    throw new NntpException('Failed to decompress NNTP overview response', operation: 'XOVER');
                }

                $metadata = $this->stream->metadata();

                if (($metadata['timed_out'] ?? false) === true) {
                    throw new NntpException(
                        'NNTP compressed response timed out',
                        operation: 'XOVER',
                        timedOut: true,
                    );
                }

                throw new NntpException('NNTP connection closed during compressed response', operation: 'XOVER');
            }

            if (strlen($chunk) > $maxBytes - strlen($wireData)) {
                $this->exceededLimit('compressed response');
            }
            $wireData .= $chunk;
        }
    }

    /** @return list<string> */
    private static function splitDecompressedResponse(string $decompressed): array
    {
        $decompressed = rtrim($decompressed, "\r\n");

        if (substr_count($decompressed, "\r\n") + 1 > self::limit('nntp_response_lines', 100_000)) {
            throw new NntpException('NNTP response exceeds line limit', operation: 'XOVER');
        }

        $offset = 0;
        $length = strlen($decompressed);
        $maxLineBytes = self::limit('nntp_line_bytes', 1_048_576);
        while ($offset < $length) {
            $end = strpos($decompressed, "\r\n", $offset);
            $end = $end === false ? $length : $end;
            if ($end - $offset + 2 > $maxLineBytes) {
                throw new NntpException('NNTP overview line exceeds payload limit', operation: 'XOVER');
            }
            $offset = $end + 2;
        }

        return $decompressed === '' ? [] : explode("\r\n", $decompressed);
    }

    private static function limit(string $key, int $default): int
    {
        return max(1, (int) config('spotengine.payload_limits.' . $key, $default));
    }

    private function readLine(string $operation, ?int $maxBytes = null): string
    {
        $maxBytes ??= $operation === 'status response' ? 4096 : self::limit('nntp_line_bytes', 1_048_576);
        while (($newlinePosition = strpos($this->readBuffer, "\n")) === false) {
            if (strlen($this->readBuffer) >= $maxBytes) {
                $this->exceededLimit($operation);
            }
            $chunk = $this->stream->read(min($this->readChunkSize, $maxBytes - strlen($this->readBuffer)));

            if ($chunk === false || $chunk === '') {
                $metadata = $this->stream->metadata();

                if (($metadata['timed_out'] ?? false) === true) {
                    throw new NntpException(
                        "NNTP {$operation} timed out",
                        operation: $operation,
                        timedOut: true,
                    );
                }

                throw new NntpException("NNTP connection closed during {$operation}", operation: $operation);
            }

            $this->readBuffer .= $chunk;
        }

        if ($newlinePosition + 1 > $maxBytes) {
            $this->exceededLimit($operation);
        }

        $line = substr($this->readBuffer, 0, $newlinePosition + 1);
        $this->readBuffer = substr($this->readBuffer, $newlinePosition + 1);

        return rtrim($line, "\r\n");
    }

    private function exceededLimit(string $operation): never
    {
        $this->readBuffer = '';
        $this->stream->close();
        throw new NntpException("NNTP {$operation} exceeds payload limit", operation: $operation);
    }
}
