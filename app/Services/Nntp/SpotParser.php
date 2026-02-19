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

            // Extract subcategories
            foreach ($posting->Category->Sub ?? [] as $sub) {
                $subCode = (string) $sub;
                if ($subCode !== '' && $subCode !== '0') {
                    $subs[] = $subCode;
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
