<?php

declare(strict_types=1);

namespace App\Services\Nntp;

/**
 * NZB Generator
 *
 * Fetches NZB from Usenet on-demand and generates NZB XML
 */
class NzbGenerator
{
    public function __construct(private readonly NntpClient $nntp) {}

    /**
     * Fetch and decode NZB from Usenet
     *
     * @param  array<string>  $segments  NZB segment message IDs
     * @param  string  $group  Newsgroup to fetch from
     * @return string NZB XML content
     */
    public function fetchNzb(array $segments, string $group): string
    {
        if ($segments === []) {
            throw new \InvalidArgumentException('No NZB segments provided');
        }

        // Select the group
        $this->nntp->group($group);

        // Fetch each segment and combine
        $rawParts = [];

        foreach ($segments as $segmentId) {
            $rawParts[] = $this->nntp->body($segmentId);
        }

        $rawData = implode('', $rawParts);

        if ($rawData === '' || $rawData === '0') {
            throw new \RuntimeException('Empty response from NNTP server');
        }

        // Decode the NZB
        return $this->decodeNzb($rawData);
    }

    /**
     * Decode NZB data (various formats)
     */
    private function decodeNzb(string $data): string
    {
        // Step 1: Check if it's yEnc encoded and decode
        if (str_contains($data, '=ybegin')) {
            $data = $this->yencDecode($data);

            if ($data === '' || $data === '0') {
                throw new \RuntimeException('yEnc decoding failed - empty result');
            }
        }

        // Step 2: Strip any trailing CRLF that might have been added
        $data = rtrim($data, "\r\n");

        // Step 3: Apply Spotnet special encoding reversal
        // Spotnet escapes certain bytes that cause issues in NNTP:
        // =A -> NULL, =B -> CR, =C -> LF, =D -> =
        $data = $this->unspecialZipStr($data);

        // Step 4: Try to decompress - try ALL methods
        $decompressed = $this->tryDecompress($data);
        if ($decompressed !== null) {
            $data = $decompressed;
        }

        // Step 5: Try to find NZB content in the data
        // Sometimes there's extra data before/after the XML
        if (! str_contains($data, '<nzb') && ! str_contains($data, '<?xml')) {
            // Try to find NZB start
            $nzbStart = strpos($data, '<?xml');
            if ($nzbStart === false) {
                $nzbStart = strpos($data, '<nzb');
            }

            if ($nzbStart !== false) {
                $data = substr($data, $nzbStart);
            }
        }

        // Find NZB end
        $nzbEnd = strrpos($data, '</nzb>');
        if ($nzbEnd !== false) {
            $data = substr($data, 0, $nzbEnd + 6);
        }

        // Final validation
        if (! str_contains($data, '<nzb') && ! str_contains($data, '<?xml')) {
            $hex = bin2hex(substr($data, 0, 20));
            throw new \RuntimeException("Invalid NZB format. First 20 bytes hex: $hex");
        }

        return $data;
    }

    /**
     * Decode Spotnet special escape encoding
     *
     * Spotnet escapes certain bytes that cause issues in NNTP transport:
     * =A -> NULL (\0)
     * =B -> CR (\r)
     * =C -> LF (\n)
     * =D -> = (literal equals)
     */
    private function unspecialZipStr(string $data): string
    {
        return str_replace(
            ['=C', '=B', '=A', '=D'],
            ["\n", "\r", "\0", '='],
            $data
        );
    }

    /**
     * Try various decompression methods
     *
     * Spotnet NZB segments can use various compression formats:
     * - Raw deflate (most common)
     * - Zlib (with header)
     * - Gzip
     *
     * The data may have header bytes that need to be skipped
     */
    private function tryDecompress(string $data): ?string
    {
        // Already XML?
        if (str_contains($data, '<nzb') || str_contains($data, '<?xml')) {
            return $data;
        }

        // Strip any trailing CRLF that might be present
        $data = rtrim($data, "\r\n");

        $firstBytes = \strlen($data) >= 2 ? [\ord($data[0]), \ord($data[1])] : [0, 0];

        // Try gzip (1f 8b)
        if ($firstBytes[0] === 0x1F && $firstBytes[1] === 0x8B) {
            $result = @gzdecode($data);
            if ($result !== false) {
                return $result;
            }
        }

        // Try zlib (78 xx) - various compression levels
        if ($firstBytes[0] === 0x78) {
            $result = @gzuncompress($data);
            if ($result !== false) {
                return $result;
            }
        }

        // Try raw deflate at offset 0
        $result = @gzinflate($data);
        if ($result !== false && $this->looksLikeNzb($result)) {
            return $result;
        }

        // Try with different offsets (skip possible headers)
        // Some implementations add custom headers before the compressed data
        for ($offset = 1; $offset <= 20; $offset++) {
            if (\strlen($data) > $offset) {
                // Try raw deflate
                $result = @gzinflate(substr($data, $offset));
                if ($result !== false && $this->looksLikeNzb($result)) {
                    return $result;
                }

                // Try zlib
                $result = @gzuncompress(substr($data, $offset));
                if ($result !== false && $this->looksLikeNzb($result)) {
                    return $result;
                }

                // Try gzip
                $result = @gzdecode(substr($data, $offset));
                if ($result !== false && $this->looksLikeNzb($result)) {
                    return $result;
                }
            }
        }

        // Try prepending zlib header and then decompressing
        // Some data might be raw deflate that needs a zlib wrapper
        $zlibHeaders = [
            "\x78\x9c", // Default compression
            "\x78\x01", // No compression
            "\x78\xda", // Best compression
            "\x78\x5e", // Low compression
        ];

        foreach ($zlibHeaders as $header) {
            // Calculate and append Adler-32 checksum for valid zlib stream
            $zlibData = $header.$data;
            $result = @gzuncompress($zlibData);
            if ($result !== false && $this->looksLikeNzb($result)) {
                return $result;
            }
        }

        // Try bzdecompress if available
        if (function_exists('bzdecompress')) {
            $result = @bzdecompress($data);
            if (\is_string($result) && $this->looksLikeNzb($result)) {
                return $result;
            }
        }

        // Try base64 decode + decompress (some older spots use this)
        $decoded = @base64_decode($data, true);
        if ($decoded !== false && $decoded !== '') {
            $result = @gzinflate($decoded);
            if ($result !== false && $this->looksLikeNzb($result)) {
                return $result;
            }
            $result = @gzuncompress($decoded);
            if ($result !== false && $this->looksLikeNzb($result)) {
                return $result;
            }
            $result = @gzdecode($decoded);
            if ($result !== false && $this->looksLikeNzb($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Check if data looks like NZB XML
     */
    private function looksLikeNzb(string $data): bool
    {
        return str_contains($data, '<nzb') ||
               str_contains($data, '<?xml') ||
               str_contains($data, '<file') ||
               str_contains($data, '<segment');
    }

    /**
     * Decode yEnc encoded data
     */
    private function yencDecode(string $data): string
    {
        $lines = explode("\n", $data);
        $decoded = '';
        $inData = false;

        foreach ($lines as $line) {
            // Remove CR if present
            $line = rtrim($line, "\r");

            if (str_starts_with($line, '=ybegin')) {
                $inData = true;

                continue;
            }

            if (str_starts_with($line, '=ypart')) {
                continue;
            }

            if (str_starts_with($line, '=yend')) {
                $inData = false;

                continue;
            }

            if (! $inData || $line === '') {
                continue;
            }

            // Decode the line
            $decoded .= $this->yencDecodeLine($line);
        }

        return $decoded;
    }

    /**
     * Decode a single yEnc line
     */
    private function yencDecodeLine(string $line): string
    {
        $result = '';
        $len = \strlen($line);
        $i = 0;

        while ($i < $len) {
            $byte = \ord($line[$i]);

            if ($byte === 0x3D) { // '=' escape character
                $i++;
                if ($i < $len) {
                    // Escaped byte: subtract 64, then 42
                    $result .= \chr((\ord($line[$i]) - 64 - 42 + 256) % 256);
                }
            } else {
                // Normal byte: subtract 42
                $result .= \chr(($byte - 42 + 256) % 256);
            }

            $i++;
        }

        return $result;
    }

    /**
     * Generate a simple NZB from spot data (for spots that have embedded NZB info)
     *
     * @param  array<string, mixed>  $spot
     * @param  array<string>  $segments
     */
    public static function generateNzb(array $spot, array $segments): string
    {
        $title = htmlspecialchars((string) $spot['title'], ENT_XML1, 'UTF-8');
        $poster = htmlspecialchars($spot['poster'] ?? 'Unknown', ENT_XML1, 'UTF-8');
        $date = isset($spot['spot_posted_at'])
            ? (strtotime((string) $spot['spot_posted_at']) ?: time())
            : time();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<!DOCTYPE nzb PUBLIC "-//newzBin//DTD NZB 1.1//EN" "http://www.newzbin.com/DTD/nzb/nzb-1.1.dtd">'."\n";
        $xml .= '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb">'."\n";
        $xml .= '  <head>'."\n";
        $xml .= "    <meta type=\"title\">$title</meta>\n";
        $xml .= "    <meta type=\"poster\">$poster</meta>\n";
        $xml .= "    <meta type=\"date\">$date</meta>\n";
        $xml .= '  </head>'."\n";

        // Create a single file entry with all segments
        $xml .= '  <file poster="'.$poster.'" date="'.$date.'" subject="'.$title.'">'."\n";
        $xml .= '    <groups>'."\n";
        $xml .= '      <group>free.pt</group>'."\n";
        $xml .= '    </groups>'."\n";
        $xml .= '    <segments>'."\n";

        $size = $spot['file_size'] ?? 0;
        $segmentSize = $size > 0 ? (int) ceil($size / max(\count($segments), 1)) : 750000;

        $segmentNum = 1;
        foreach ($segments as $segment) {
            $segment = htmlspecialchars($segment, ENT_XML1, 'UTF-8');
            $xml .= "      <segment bytes=\"$segmentSize\" number=\"$segmentNum\">$segment</segment>\n";
            $segmentNum++;
        }

        $xml .= '    </segments>'."\n";
        $xml .= '  </file>'."\n";

        return $xml.('</nzb>'."\n");
    }
}
