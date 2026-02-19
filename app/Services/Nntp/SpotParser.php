<?php

declare(strict_types=1);

namespace App\Services\Nntp;

/**
 * Spotnet Protocol Parser
 *
 * Parses Spotnet XML format from article headers
 */
class SpotParser
{
    /**
     * Parse a spot from XOVER overview data — the Spotweb-style approach.
     *
     * Extracts all spot metadata from the From and Subject NNTP headers
     * without needing HEAD or X-XML. The From domain encodes category,
     * subcategories and filesize; Subject carries the title (and optional tag
     * separated by a pipe). Returns null for non-Spotnet articles.
     *
     * X-XML fields (description, nzb_segments, image_segment, website) are
     * left null/empty and can be populated lazily via HEAD when needed.
     *
     * Spotnet From header format:
     *   [Nickname] <[pubkey.usersig]@[CAT][KEYID][SUBCATS].[SIZE].[RAND].[DATE].[...][SIG]>
     *
     * @param  array{subject: string, from: string, date: string, message_id: string}  $overview
     * @return array<string, mixed>|null
     */
    public function parseFromOverview(array $overview): ?array
    {
        $from = $overview['from'];
        $subject = $overview['subject'];
        $date = $overview['date'];
        $messageId = $overview['message_id'];

        // Find the <address> part of the From header.
        $ltPos = strpos($from, '<');

        if ($ltPos === false) {
            return null;
        }

        // Extract address (inside <...>) and split on the last @ to get the domain.
        $address = substr($from, $ltPos + 1, -1);
        $atParts = explode('@', $address);

        if (\count($atParts) < 2) {
            return null;
        }

        // Domain is the last @-part (nickname might itself contain @).
        $domain = $atParts[\count($atParts) - 1];
        $fields = explode('.', $domain);

        // Spotnet requires at least 6 dot-delimited fields in the domain.
        if (\count($fields) < 6) {
            return null;
        }

        $field0 = $fields[0];

        if (\strlen($field0) < 2) {
            return null;
        }

        // First character encodes the main category (1=Image, 2=Sound, 3=Games, 4=Apps).
        $catChar = $field0[0];

        if ($catChar < '1' || $catChar > '4') {
            return null;
        }

        $categoryCode = str_pad($catChar, 2, '0', STR_PAD_LEFT); // '1'→'01' etc.

        // Second character is the key ID (0–7).
        $keyId = (int) $field0[1];

        if ($keyId < 0) {
            return null;
        }

        // Remaining characters in field0 are the subcategory string.
        $subcategories = $this->parseSubcatString(substr($field0, 2));

        $fileSize = (int) $fields[1];

        // Subject: decode MIME encoding if present, then split on first pipe.
        // Old Spotnet headers are raw ISO-8859-1, so ensure UTF-8 after decoding.
        $decodedSubject = $this->toUtf8($this->decodeMimeHeader($subject));
        $pipePos = strpos($decodedSubject, '|');

        if ($pipePos !== false) {
            $title = trim(substr($decodedSubject, 0, $pipePos));
            $tag = trim(substr($decodedSubject, $pipePos + 1)) ?: null;
        } else {
            $title = trim($decodedSubject);
            $tag = null;
        }

        if ($title === '') {
            return null;
        }

        // Poster name: everything before the < in the From header.
        $poster = $this->toUtf8(trim(substr($from, 0, $ltPos)));

        // Timestamp from Date header; clamp futures to now.
        $timestamp = strtotime($date);
        $now = time();

        if ($timestamp === false || $timestamp > $now + 86400) {
            $timestamp = $now;
        }

        return [
            'message_id' => $messageId,
            'poster' => $poster,
            'title' => $title,
            'tag' => $tag !== null ? $this->toUtf8($tag) : null,
            'category_code' => $categoryCode,
            'subcategories' => $subcategories,
            'file_size' => $fileSize,
            'spot_posted_at' => date('Y-m-d H:i:s', $timestamp),
            // X-XML fields — populated lazily via HEAD when needed:
            'description' => null,
            'nzb_segments' => [],
            'image_segment' => null,
            'website' => null,
            'xml_signature' => null,
            'poster_key_id' => null,
            'is_verified' => false,
        ];
    }

    /**
     * Parse the subcategory substring from a Spotnet From domain first field.
     *
     * Format: letter+digits pairs (e.g. "a11b03c04d44z02" or "a9b4c0d5").
     * Returns codes normalised to no leading zeros: ["a11", "b3", "c4", ...].
     *
     * @return array<string>
     */
    private function parseSubcatString(string $str): array
    {
        if ($str === '') {
            return [];
        }

        $valid = ['a', 'b', 'c', 'd', 'z'];
        $subs = [];
        $tmp = '';
        $str = strtolower($str).'!'; // sentinel to flush the last entry

        for ($i = 0, $len = \strlen($str); $i < $len; $i++) {
            $ch = $str[$i];

            if (! is_numeric($ch) && $tmp !== '') {
                if (\in_array($tmp[0], $valid, true)) {
                    $subs[] = $tmp[0].(int) substr($tmp, 1); // strip leading zeros
                }

                $tmp = '';
            }

            if (\in_array($ch, $valid, true) || is_numeric($ch)) {
                $tmp .= $ch;
            }
        }

        return $subs;
    }

    /**
     * Decode RFC 2047 MIME-encoded header words (=?charset?B?...?=).
     */
    private function decodeMimeHeader(string $header): string
    {
        if (! str_contains($header, '=?')) {
            return $header;
        }

        $decoded = iconv_mime_decode($header, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : $header;
    }

    /**
     * Ensure a string is valid UTF-8, converting from ISO-8859-1 if needed.
     *
     * Old Spotnet spots use raw ISO-8859-1 in Subject and From without any
     * MIME encoding. mb_check_encoding detects this and mb_convert_encoding
     * promotes the bytes to their correct UTF-8 counterparts.
     */
    private function toUtf8(string $str): string
    {
        if (mb_check_encoding($str, 'UTF-8')) {
            return $str;
        }

        return mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Parse a spot from article headers
     *
     * @param  array<string, string>  $headers  Article headers
     * @return array<string, mixed>|null Parsed spot data or null if invalid
     */
    public function parseFromHeaders(array $headers): ?array
    {
        // Look for X-XML header which contains the Spotnet XML
        $xml = $headers['x-xml'] ?? null;

        if (! $xml) {
            return null;
        }

        try {
            return $this->parseXml($xml, $headers);
        } catch (\Throwable $e) {
            // Log error but don't fail
            error_log('Failed to parse spot XML: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Parse Spotnet XML
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    public function parseXml(string $xml, array $headers = []): ?array
    {
        // Clean up XML - sometimes it's malformed
        $xml = trim($xml);

        // Suppress XML errors
        $useErrors = libxml_use_internal_errors(true);

        $doc = simplexml_load_string($xml);
        libxml_clear_errors();

        libxml_use_internal_errors($useErrors);

        if ($doc === false) {
            return null;
        }

        // Navigate to Posting element
        $posting = $doc->Posting ?? $doc;

        if (! property_exists($posting, 'Title') || $posting->Title === null) {
            return null;
        }

        // Parse category and subcategories
        $category = $this->parseCategory($posting);

        // Parse NZB segments
        $nzbSegments = $this->parseNzbSegments($posting);

        // Parse image segment
        $imageSegment = null;
        if (property_exists($posting->Image, 'Segment') && $posting->Image->Segment !== null) {
            $imageSegment = (string) $posting->Image->Segment;
        }

        // Build the spot array
        $spot = [
            'message_id' => $headers['message-id'] ?? trim($headers['message_id'] ?? '', '<>'),
            'poster' => (string) ($posting->Poster ?? $headers['from'] ?? 'Unknown'),
            'title' => (string) $posting->Title,
            'description' => property_exists($posting, 'Description') && $posting->Description !== null ? (string) $posting->Description : null,
            'tag' => property_exists($posting, 'Tag') && $posting->Tag !== null ? (string) $posting->Tag : null,
            'website' => property_exists($posting, 'Website') && $posting->Website !== null ? (string) $posting->Website : null,
            'category_code' => $category['main'],
            'subcategories' => $category['subs'],
            'file_size' => property_exists($posting, 'Size') && $posting->Size !== null ? (int) (string) $posting->Size : 0,
            'image_segment' => $imageSegment,
            'nzb_segments' => $nzbSegments,
            'spot_posted_at' => property_exists($posting, 'Created') && $posting->Created !== null
                ? date('Y-m-d H:i:s', (int) (string) $posting->Created)
                : ($headers['date'] ?? date('Y-m-d H:i:s')),
            'xml_signature' => $headers['x-xml-signature'] ?? null,
            'poster_key_id' => $this->extractKeyId($headers['x-user-key'] ?? null),
        ];

        // Try to parse date from header if not in XML
        if ((! property_exists($posting, 'Created') || $posting->Created === null) && isset($headers['date'])) {
            $timestamp = strtotime($headers['date']);
            if ($timestamp !== false) {
                $spot['spot_posted_at'] = date('Y-m-d H:i:s', $timestamp);
            }
        }

        return $spot;
    }

    /**
     * Parse category from posting
     *
     * @return array{main: string, subs: array<string>}
     */
    private function parseCategory(\SimpleXMLElement $posting): array
    {
        $main = '01'; // Default to Image category
        $subs = [];

        if (property_exists($posting, 'Category') && $posting->Category !== null) {
            $categoryText = trim((string) $posting->Category);

            // Extract main category (first 2 digits)
            if (preg_match('/^(\d{2})/', $categoryText, $matches)) {
                $main = $matches[1];
            }

            // Extract subcategories (normalise to no leading zeros for consistency with parseFromOverview).
            foreach ($posting->Category->Sub ?? [] as $sub) {
                $subCode = (string) $sub;

                if ($subCode !== '' && $subCode !== '0' && \strlen($subCode) >= 2) {
                    $subs[] = $subCode[0].(int) substr($subCode, 1);
                }
            }
        }

        return [
            'main' => $main,
            'subs' => $subs,
        ];
    }

    /**
     * Parse NZB segments
     *
     * @return array<string>
     */
    private function parseNzbSegments(\SimpleXMLElement $posting): array
    {
        $segments = [];

        if (property_exists($posting, 'NZB') && $posting->NZB !== null) {
            foreach ($posting->NZB->Segment ?? [] as $segment) {
                $segmentId = (string) $segment;
                if ($segmentId !== '' && $segmentId !== '0') {
                    $segments[] = $segmentId;
                }
            }
        }

        return $segments;
    }

    /**
     * Extract key ID from X-User-Key header
     */
    private function extractKeyId(?string $userKey): ?string
    {
        if (! $userKey) {
            return null;
        }

        // Try to extract modulus from RSA key XML and hash it
        if (preg_match('/<Modulus>([^<]+)<\/Modulus>/', $userKey, $matches)) {
            return substr(md5($matches[1]), 0, 16);
        }

        return null;
    }

    /**
     * Map old Spotnet category codes to our normalized system
     *
     * Spotnet uses:
     * - z0 = Image (Movies/Series)
     * - z1 = Sound
     * - z2 = Games
     * - z3 = Applications
     *
     * We use:
     * - 01 = Image
     * - 02 = Sound
     * - 03 = Games
     * - 04 = Applications
     */
    public static function normalizeCategory(string $code): string
    {
        // Handle old z-prefix format
        if (str_starts_with($code, 'z')) {
            $num = (int) substr($code, 1, 1);

            return str_pad((string) ($num + 1), 2, '0', STR_PAD_LEFT);
        }

        // Already normalized
        return substr($code, 0, 2);
    }

    /**
     * Normalize subcategory codes
     *
     * @param  array<string>  $subs
     * @return array<string>
     */
    public static function normalizeSubcategories(array $subs): array
    {
        return array_map(function (string $sub): string {
            // Convert old format (e.g., "z0d03") to new format (e.g., "01d03")
            if (str_starts_with($sub, 'z')) {
                $mainNum = (int) substr($sub, 1, 1) + 1;
                $main = str_pad((string) $mainNum, 2, '0', STR_PAD_LEFT);

                return $main.substr($sub, 2);
            }

            return $sub;
        }, $subs);
    }
}
