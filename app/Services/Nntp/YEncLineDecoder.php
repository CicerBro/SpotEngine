<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final class YEncLineDecoder
{
    public function decode(string $line, bool $rejectIncompleteEscape = true): string
    {
        if (str_ends_with($line, '=')) {
            if ($rejectIncompleteEscape) {
                throw new \UnexpectedValueException('The yEnc line ends with an incomplete escape.');
            }

            $line = substr($line, 0, -1);
        }

        if (str_contains($line, '=')) {
            $line = preg_replace_callback(
                '/=(.)/s',
                static fn (array $match): string => chr((ord($match[1]) - 64) & 0xFF),
                $line,
            );

            if ($line === null) {
                throw new \UnexpectedValueException('The yEnc escapes could not be decoded.');
            }
        }

        return strtr($line, ...$this->translationTable());
    }

    /**
     * @param  list<string>  $lines
     */
    public function decodeLines(array $lines, bool $rejectIncompleteEscape = true): string
    {
        foreach ($lines as $index => $line) {
            if (! str_ends_with($line, '=')) {
                continue;
            }

            if ($rejectIncompleteEscape) {
                throw new \UnexpectedValueException('The yEnc line ends with an incomplete escape.');
            }

            $lines[$index] = substr($line, 0, -1);
        }

        return $this->decode(implode('', $lines));
    }

    /**
     * @return array{string, string}
     */
    private function translationTable(): array
    {
        /** @var array{string, string}|null $translationTable */
        static $translationTable = null;

        if ($translationTable === null) {
            $encodedBytes = range(0, 255);
            $decodedBytes = array_map(
                static fn (int $byte): int => ($byte - 42) & 0xFF,
                $encodedBytes,
            );

            $translationTable = [
                pack('C*', ...$encodedBytes),
                pack('C*', ...$decodedBytes),
            ];
        }

        return $translationTable;
    }
}
