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

        $decompressed = @gzuncompress($wireData);

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
    public function readTextResponse(): array
    {
        $lines = [];

        while (true) {
            $line = $this->readLine('multiline response');

            if ($line === '.') {
                return $lines;
            }

            $lines[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
        }
    }

    public function readBodyResponse(): string
    {
        return implode("\r\n", $this->readTextResponse());
    }

    /** @return list<string> */
    public function readCompressedTextResponse(): array
    {
        $wireData = $this->readBuffer;
        $this->readBuffer = '';
        $foundTerminator = false;

        while (true) {
            if (str_ends_with($wireData, ".\r\n")) {
                $foundTerminator = true;
                $decompressed = @gzuncompress(substr($wireData, 0, -3));

                if ($decompressed !== false) {
                    return self::splitDecompressedResponse($decompressed);
                }
            }

            $chunk = $this->stream->read($this->readChunkSize);

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

            $wireData .= $chunk;
        }
    }

    /** @return list<string> */
    private static function splitDecompressedResponse(string $decompressed): array
    {
        $decompressed = rtrim($decompressed, "\r\n");

        return $decompressed === '' ? [] : explode("\r\n", $decompressed);
    }

    private function readLine(string $operation): string
    {
        while (($newlinePosition = strpos($this->readBuffer, "\n")) === false) {
            $chunk = $this->stream->read($this->readChunkSize);

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

        $line = substr($this->readBuffer, 0, $newlinePosition + 1);
        $this->readBuffer = substr($this->readBuffer, $newlinePosition + 1);

        return rtrim($line, "\r\n");
    }
}
