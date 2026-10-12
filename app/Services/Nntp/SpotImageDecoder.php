<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final readonly class SpotImageDecoder
{
    public function __construct(
        private YEncLineDecoder $yEncLineDecoder = new YEncLineDecoder,
    ) {}

    /**
     * Decode one or more NNTP article bodies into their original binary payload.
     *
     * @param  iterable<string>  $articleBodies
     */
    public function decode(iterable $articleBodies): string
    {
        $parts = [];
        $inputBytes = 0;
        $inputLines = 0;
        $decodedBytes = 0;
        $maxDecodedBytes = max(1, (int) config('spotengine.payload_limits.image_decoded_bytes', 25_000_000));

        foreach ($articleBodies as $position => $articleBody) {
            $inputBytes += strlen($articleBody);
            $inputLines += substr_count($articleBody, "\n") + substr_count($articleBody, "\r") - substr_count($articleBody, "\r\n") + 1;
            if ($inputBytes > max(1, (int) config('spotengine.payload_limits.image_input_bytes', 50_000_000))
                || $inputLines > max(1, (int) config('spotengine.payload_limits.nntp_response_lines', 100_000))
                || count($parts) >= max(1, (int) config('spotengine.payload_limits.image_segments', 64))) {
                throw new \UnexpectedValueException('The image input exceeds payload limit.');
            }
            $part = $this->decodeArticle($articleBody, $position, $maxDecodedBytes - $decodedBytes);
            $decodedBytes += strlen($part['data']);
            $parts[] = $part;
        }

        if ($parts === []) {
            throw new \UnexpectedValueException('The image has no NNTP article bodies.');
        }

        usort($parts, static fn (array $left, array $right): int => $left['part'] <=> $right['part']);

        return implode('', array_column($parts, 'data'));
    }

    /**
     * @return array{part: int, data: string}
     */
    private function decodeArticle(string $articleBody, int $position, int $maxBytes): array
    {
        $trimmedBody = ltrim($articleBody, "\r\n");

        if (str_starts_with($trimmedBody, '=ybegin ')) {
            return $this->decodeYEnc($trimmedBody, $position, $maxBytes);
        }

        if (preg_match('/^begin-base64\s+\d+\s+.+$/m', $trimmedBody) === 1
            || stripos($trimmedBody, 'Content-Transfer-Encoding: base64') !== false) {
            return ['part' => $position + 1, 'data' => $this->decodeBase64($trimmedBody, $maxBytes)];
        }

        if (preg_match('/^begin\s+[0-7]{3}\s+.+$/m', $trimmedBody) === 1) {
            return ['part' => $position + 1, 'data' => $this->decodeUu($trimmedBody, $maxBytes)];
        }

        return [
            'part' => $position + 1,
            'data' => $this->decodeSpotnetBinary($articleBody, $maxBytes),
        ];
    }

    private function decodeSpotnetBinary(string $articleBody, int $maxBytes): string
    {
        $transportUnframed = str_replace(["\r\n", "\n", "\r"], '', $articleBody);
        $decodedLength = strlen($transportUnframed);
        foreach (['=C', '=B', '=A', '=D'] as $escape) {
            $decodedLength -= substr_count($transportUnframed, $escape);
        }
        $this->checkDecodedSize($decodedLength, $maxBytes);

        return str_replace(
            ['=C', '=B', '=A', '=D'],
            ["\n", "\r", "\0", '='],
            $transportUnframed,
        );
    }

    /**
     * @return array{part: int, data: string}
     */
    private function decodeYEnc(string $articleBody, int $position, int $maxBytes): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $articleBody);

        if ($lines === false || $lines === []) {
            throw new \UnexpectedValueException('The yEnc image body is empty.');
        }

        $begin = $this->parseYEncProperties(array_shift($lines));
        $part = isset($begin['part']) ? (int) $begin['part'] : $position + 1;
        $encodedLines = [];
        $end = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, '=ypart ')) {
                continue;
            }

            if (str_starts_with($line, '=yend ')) {
                $end = $this->parseYEncProperties($line);
                break;
            }

            $encodedLines[] = $line;
        }

        if ($end === null) {
            throw new \UnexpectedValueException('The yEnc image body has no end marker.');
        }

        $decoded = $this->decodeYEncLines($encodedLines, $maxBytes);
        $expectedSize = isset($end['size']) ? (int) $end['size'] : null;

        if ($expectedSize !== null && strlen($decoded) !== $expectedSize) {
            throw new \UnexpectedValueException('The yEnc image part size does not match its end marker.');
        }

        $expectedCrc = strtolower($end['pcrc32'] ?? $end['crc32'] ?? '');

        if ($expectedCrc !== '' && hash('crc32b', $decoded) !== $expectedCrc) {
            throw new \UnexpectedValueException('The yEnc image part checksum is invalid.');
        }

        return ['part' => $part, 'data' => $decoded];
    }

    /**
     * @param  list<string>  $encodedLines
     */
    private function decodeYEncLines(array $encodedLines, int $maxBytes): string
    {
        $decoded = '';
        foreach ($encodedLines as $line) {
            $escapes = preg_match_all('/=./s', $line);
            $this->checkDecodedSize(strlen($decoded) + strlen($line) - (int) $escapes, $maxBytes);
            $decoded .= $this->yEncLineDecoder->decode($line);
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function parseYEncProperties(string $line): array
    {
        preg_match_all('/(?:^|\s)([a-z0-9]+)=([^\s]+)/i', $line, $matches, PREG_SET_ORDER);
        $properties = [];

        foreach ($matches as $match) {
            $properties[strtolower($match[1])] = $match[2];
        }

        return $properties;
    }

    private function decodeBase64(string $articleBody, int $maxBytes): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $articleBody);

        if ($lines === false) {
            throw new \UnexpectedValueException('The base64 image body could not be split into lines.');
        }

        $payloadLines = [];
        $collecting = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'begin-base64 ')) {
                $collecting = true;

                continue;
            }

            if (stripos($line, 'Content-Transfer-Encoding: base64') === 0) {
                $collecting = true;

                continue;
            }

            if ($line === '====' || ($collecting && str_starts_with($line, '--'))) {
                break;
            }

            if ($collecting && $line !== '' && ! str_contains($line, ':')) {
                $payloadLines[] = trim($line);
            }
        }

        $payload = implode('', $payloadLines);
        $payload = (string) preg_replace('/\s+/', '', $payload);
        $this->checkDecodedSize(intdiv(strlen(rtrim($payload, '=')) * 3, 4), $maxBytes);
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new \UnexpectedValueException('The base64 image body is invalid.');
        }

        return $decoded;
    }

    private function decodeUu(string $articleBody, int $maxBytes): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $articleBody);

        if ($lines === false) {
            throw new \UnexpectedValueException('The uuencoded image body could not be split into lines.');
        }

        $payloadLines = [];
        $collecting = false;
        $decodedLength = 0;

        foreach ($lines as $line) {
            if (preg_match('/^begin\s+[0-7]{3}\s+.+$/', $line) === 1) {
                $collecting = true;

                continue;
            }

            if ($collecting && $line === 'end') {
                break;
            }

            if ($collecting) {
                $decodedLength += $line === '' ? 0 : ((ord($line[0]) - 32) & 63);
                $this->checkDecodedSize($decodedLength, $maxBytes);
                $payloadLines[] = $line;
            }
        }

        $decoded = convert_uudecode(implode("\n", $payloadLines) . "\n");

        if ($decoded === false) {
            throw new \UnexpectedValueException('The uuencoded image body is invalid.');
        }

        return $decoded;
    }

    private function checkDecodedSize(int $bytes, int $maxBytes): void
    {
        if ($bytes > $maxBytes) {
            throw new \UnexpectedValueException('The decoded image exceeds payload limit.');
        }
    }
}
