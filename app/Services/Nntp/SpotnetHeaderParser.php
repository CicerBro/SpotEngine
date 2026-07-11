<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final class SpotnetHeaderParser
{
    /**
     * @return array{headers: array<string, string>, current_header: string, current_value: string}
     */
    public function start(): array
    {
        return [
            'headers' => [],
            'current_header' => '',
            'current_value' => '',
        ];
    }

    /**
     * @param  iterable<string>  $lines
     * @param  array<string, bool>|null  $wantedHeaders
     * @return array<string, string>
     */
    public function parse(iterable $lines, ?array $wantedHeaders = null): array
    {
        $state = $this->start();

        foreach ($lines as $line) {
            $this->consume($state, $line, $wantedHeaders);
        }

        return $this->finish($state);
    }

    /**
     * @param  array{headers: array<string, string>, current_header: string, current_value: string}  $state
     * @param  array<string, bool>|null  $wantedHeaders
     */
    public function consume(array &$state, string $line, ?array $wantedHeaders = null): void
    {
        if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t")) {
            if ($state['current_header'] !== '') {
                $state['current_value'] .= ltrim($line);
            }

            return;
        }

        $colonPosition = strpos($line, ':');

        if ($colonPosition === false) {
            return;
        }

        $headerName = strtolower(substr($line, 0, $colonPosition));
        $headerValue = ltrim(substr($line, $colonPosition + 1));

        if ($headerName === $state['current_header']) {
            $state['current_value'] .= $headerValue;

            return;
        }

        $this->storeHeader($state['headers'], $state['current_header'], $state['current_value']);

        if ($wantedHeaders === null || isset($wantedHeaders[$headerName])) {
            $state['current_header'] = $headerName;
            $state['current_value'] = $headerValue;
        } else {
            $state['current_header'] = '';
            $state['current_value'] = '';
        }
    }

    /**
     * @param  array{headers: array<string, string>, current_header: string, current_value: string}  $state
     * @return array<string, string>
     */
    public function finish(array $state): array
    {
        $this->storeHeader($state['headers'], $state['current_header'], $state['current_value']);

        return $state['headers'];
    }

    /** @param array<string, string> $headers */
    private function storeHeader(array &$headers, string $name, string $value): void
    {
        if ($name !== '') {
            $headers[$name] = $this->decodeHeader($value);
        }
    }

    public function decodeHeader(string $header): string
    {
        if (! str_contains($header, '=?')) {
            return $header;
        }

        $decoded = iconv_mime_decode($header, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $header;
    }
}
