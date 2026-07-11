<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use Illuminate\Support\Facades\Log;

/**
 * Spotnet Protocol Parser
 *
 * Parses Spotnet XML format from article headers
 */
class SpotParser
{
    public function __construct(private readonly SigningService $signer = new SigningService) {}

    /**
     * Parse a spot from XOVER overview data — the Spotweb-style approach.
     *
     * Extracts all spot metadata from the From and Subject NNTP headers
     * without needing HEAD or X-XML. The From domain encodes category,
     * subcategories and filesize; Subject carries the title (and optional tag
     * separated by a pipe). Returns null for non-Spotnet articles.
     *
     * X-XML fields (description, image_segments, nzb_segments, website) are
     * left null/empty and can be populated lazily via HEAD when needed.
     *
     * Spotnet From header format:
     *   [Nickname] <[pubkey.usersig]@[CAT][KEYID][SUBCATS].[SIZE].[RAND].[DATE].[...][SIG]>
     *
     * @param  array{subject: string, from: string, date: string, message_id: string}  $overview
     * @return array{_moderation: true, command: string, target_message_id: string, poster: string, stamp: int, is_global_moderator: bool, moderator_key_id: string|null}
     *                                                                                                                                                                    | array<string, mixed>
     *                                                                                                                                                                    | null
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
        $domain = array_last($atParts);
        $localPart = implode('@', \array_slice($atParts, 0, -1));
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
        $subcategories = $this->parseSubcatString(substr($field0, 2), $categoryCode);

        $fileSize = (int) $fields[1];

        // Subject: decode MIME encoding if present, then split on first pipe.
        // Old Spotnet headers are raw ISO-8859-1, so ensure UTF-8 after decoding.
        $decodedSubject = $this->toUtf8($this->decodeMimeHeader($subject));

        // Keyid 2 articles may be Spotnet moderation commands. The key-id is
        // only a selector; the header signature must authenticate the command.
        if ($keyId === 2) {
            $moderation = $this->parseModerationCommand(
                $decodedSubject,
                $subject,
                $from,
                $ltPos,
                $messageId,
                $domain,
                $fields,
                $localPart,
                $fileSize,
                $date,
            );

            if ($moderation !== null) {
                return $moderation;
            }
        }

        // Skip Spotnet continuation articles — multi-part XML headers that were
        // too large for a single NNTP article. Their Subject starts with "DISPOSE ".
        if (stripos($decodedSubject, 'DISPOSE ') === 0) {
            return null;
        }

        $pipePos = strpos($decodedSubject, '|');

        if ($pipePos !== false) {
            $title = mb_substr(trim(substr($decodedSubject, 0, $pipePos)), 0, 500);
            $tag = mb_substr(trim(substr($decodedSubject, $pipePos + 1)), 0, 255) ?: null;
        } else {
            $title = mb_substr(trim($decodedSubject), 0, 500);
            $tag = null;
        }

        if ($title === '') {
            return null;
        }

        // Poster name: everything before the < in the From header.
        $poster = mb_substr($this->toUtf8(trim(substr($from, 0, $ltPos))), 0, 255);

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
            'image_segments' => [],
            'nzb_segments' => [],
            'website' => null,
            'xml_signature' => null,
            'poster_key_id' => null,
            'is_verified' => false,
        ];
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
            Log::warning('Failed to parse spot XML', ['error' => $e->getMessage()]);

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

        $imageSegments = $this->parseImageSegments($posting);

        // Build the spot array
        $spot = [
            'message_id' => $headers['message-id'] ?? trim($headers['message_id'] ?? '', '<>'),
            'poster' => (string) ($posting->Poster ?? $headers['from'] ?? 'Unknown'),
            'title' => (string) $posting->Title,
            'description' => property_exists($posting, 'Description') && $posting->Description !== null ? (string) $posting->Description : null,
            'tag' => property_exists($posting, 'Tag') && $posting->Tag !== null ? (string) $posting->Tag : null,
            'website' => property_exists($posting, 'Website') && $posting->Website !== null
                ? $this->sanitizeWebsite((string) $posting->Website)
                : null,
            'category_code' => $category['main'],
            'subcategories' => $category['subs'],
            'file_size' => property_exists($posting, 'Size') && $posting->Size !== null ? (int) (string) $posting->Size : 0,
            'image_segments' => $imageSegments,
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
     * Parse the subcategory substring from a Spotnet From domain first field.
     *
     * Format: letter+digits pairs (e.g. "a11b03c04d44z02" or "a9b4c0d5").
     * Returns canonical codes prefixed with the 2-digit head category
     * (e.g. ["01a11", "01b03", "01c04", ...]).
     *
     * @return array<string>
     */
    private function parseSubcatString(string $str, string $mainCategoryCode): array
    {
        if ($str === '') {
            return [];
        }

        $valid = ['a', 'b', 'c', 'd', 'z'];
        $subs = [];
        $tmp = '';
        $str = strtolower($str) . '!'; // sentinel to flush the last entry

        for ($i = 0, $len = \strlen($str); $i < $len; $i++) {
            $ch = $str[$i];

            if (! is_numeric($ch) && $tmp !== '') {
                if (\in_array($tmp[0], $valid, true)) {
                    $normalized = $this->normalizeSubcategoryCode($tmp, $mainCategoryCode);
                    if ($normalized !== null) {
                        $subs[] = $normalized;
                    }
                }

                $tmp = '';
            }

            if (\in_array($ch, $valid, true) || is_numeric($ch)) {
                $tmp .= $ch;
            }
        }

        return array_values(array_unique($subs));
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

            // Extract subcategories and normalize to canonical category codes.
            foreach ($posting->Category->Sub ?? [] as $sub) {
                $normalized = $this->normalizeSubcategoryCode((string) $sub, $main);
                if ($normalized !== null) {
                    $subs[] = $normalized;
                }
            }
        }

        return [
            'main' => $main,
            'subs' => array_values(array_unique($subs)),
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
     * @return list<string>
     */
    private function parseImageSegments(\SimpleXMLElement $posting): array
    {
        if (! property_exists($posting, 'Image') || $posting->Image === null) {
            return [];
        }

        $segments = [];

        foreach ($posting->Image->Segment ?? [] as $segment) {
            $segmentId = trim((string) $segment, '<> ');

            if ($segmentId !== '' && $segmentId !== '0' && ! strpbrk($segmentId, "\r\n")) {
                $segments[] = $segmentId;
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

        // Spotter IDs are the base64-encoded little-endian CRC32 of the public modulus.
        if (preg_match('/<Modulus>([^<]+)<\/Modulus>/', $userKey, $matches)) {
            return $this->calculateSpotterId($matches[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $fields
     * @return array{_moderation: true, command: string, target_message_id: string, poster: string, stamp: int, is_global_moderator: bool, moderator_key_id: string|null}|null
     */
    private function parseModerationCommand(
        string $decodedSubject,
        string $rawSubject,
        string $from,
        int $ltPos,
        string $messageId,
        string $domain,
        array $fields,
        string $localPart,
        int $fileSize,
        string $date,
    ): ?array {
        $parts = explode(' ', trim($decodedSubject), 2);
        $command = strtolower($parts[0]);
        $targetMessageId = trim($parts[1] ?? '', '<> ');

        if (! \in_array($command, ['delete', 'dispose', 'remove'], true) || $targetMessageId === '') {
            return null;
        }

        $lastSeparator = strrpos($domain, '.');
        $headerSignature = array_last($fields);

        if ($lastSeparator === false || $headerSignature === '') {
            return null;
        }

        $poster = trim(substr($from, 0, $ltPos));
        $signedPayload = trim($rawSubject) . substr($domain, 0, $lastSeparator) . $poster;
        $trustedKey = config('spotengine.moderation.public_keys.2');
        $isGlobalModerator = \is_array($trustedKey)
            && isset($trustedKey['modulus'], $trustedKey['exponent'])
            && $this->signer->verify(
                $signedPayload,
                $headerSignature,
                $this->publicKeyXml((string) $trustedKey['modulus'], (string) $trustedKey['exponent']),
            );

        $moderatorKeyId = null;

        if (! $isGlobalModerator) {
            [$selfSignedModulus] = array_pad(explode('.', $localPart, 2), 2, '');

            $isPersonalDispose = $fileSize === 999
                && \strlen($selfSignedModulus) > 50
                && str_starts_with(sha1("<{$messageId}>"), '0000')
                && $this->signer->verify(
                    $signedPayload,
                    $headerSignature,
                    $this->publicKeyXml($selfSignedModulus, 'AQAB'),
                );

            if (! $isPersonalDispose) {
                return null;
            }

            $moderatorKeyId = $this->calculateSpotterId($selfSignedModulus);
        }

        $timestamp = strtotime($date);
        $now = time();

        if ($timestamp === false || $timestamp > $now + 86400) {
            $timestamp = $now;
        }

        return [
            '_moderation' => true,
            'command' => $command,
            'target_message_id' => $targetMessageId,
            'poster' => mb_substr($this->toUtf8($poster), 0, 255),
            'stamp' => $timestamp,
            'is_global_moderator' => $isGlobalModerator,
            'moderator_key_id' => $moderatorKeyId,
        ];
    }

    private function publicKeyXml(string $modulus, string $exponent): string
    {
        return "<RSAKeyValue><Modulus>{$modulus}</Modulus><Exponent>{$exponent}</Exponent></RSAKeyValue>";
    }

    private function calculateSpotterId(string $preparedModulus): string
    {
        $modulus = base64_decode(str_replace(['-p', '-s', '-e'], ['+', '/', '='], $preparedModulus), true);

        if ($modulus === false) {
            return '';
        }

        $checksum = crc32($modulus);
        $littleEndian = pack('V', $checksum);

        return str_replace(['/', '+', '='], '', base64_encode($littleEndian));
    }

    private function sanitizeWebsite(string $website): ?string
    {
        $website = trim($website);
        $scheme = parse_url($website, PHP_URL_SCHEME);

        if (! \is_string($scheme) || ! \in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return filter_var($website, FILTER_VALIDATE_URL) !== false ? $website : null;
    }

    private function normalizeSubcategoryCode(string $subcategoryCode, string $mainCategoryCode): ?string
    {
        $subcategoryCode = strtolower(trim($subcategoryCode));

        if ($subcategoryCode === '' || $subcategoryCode === '0') {
            return null;
        }

        if (preg_match('/^([a-z])(\d{1,2})$/', $subcategoryCode, $matches) === 1) {
            return $mainCategoryCode . $matches[1] . sprintf('%02d', (int) $matches[2]);
        }

        if (preg_match('/^(\d{2})([a-z])(\d{1,2})$/', $subcategoryCode, $matches) === 1) {
            return $matches[1] . $matches[2] . sprintf('%02d', (int) $matches[3]);
        }

        if (preg_match('/^z([0-3])([a-z])(\d{1,2})$/', $subcategoryCode, $matches) === 1) {
            $normalizedMain = str_pad((string) (((int) $matches[1]) + 1), 2, '0', STR_PAD_LEFT);

            return $normalizedMain . $matches[2] . sprintf('%02d', (int) $matches[3]);
        }

        return null;
    }
}
