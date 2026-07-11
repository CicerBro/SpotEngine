<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;

/**
 * Lazily enriches spots that were indexed via XOVER with their full X-XML data.
 *
 * XOVER-indexed spots have description=null, nzb_segments=[], image_segment=null.
 * This service fetches the HEAD for the spot's message-ID and populates those
 * fields from the X-XML header, then persists the result to the database.
 */
class SpotEnricher
{
    private const int MAX_RETRIES = 2;

    public function __construct(
        private readonly NntpService $nntpService,
        private readonly SpotParser $parser,
        private readonly SigningService $signer,
    ) {}

    /**
     * Enrich a spot with X-XML data if it hasn't been fetched yet.
     *
     * Returns true if enrichment was performed, false if the spot was already
     * enriched or if no X-XML data could be retrieved.
     */
    public function enrich(Spot $spot): bool
    {
        if ($this->isEnriched($spot)) {
            return false;
        }

        $headers = $this->fetchHead($spot);

        if ($headers === null) {
            $spot->update(['xml_signature' => '']);

            return false;
        }

        $parsed = $this->parser->parseFromHeaders($headers);

        if ($parsed === null) {
            $spot->update(['xml_signature' => '']);

            return false;
        }

        $xmlContent = $headers['x-xml'] ?? '';
        $xmlSignature = $headers['x-xml-signature'] ?? '';
        $userKey = $headers['x-user-key'] ?? '';

        $isVerified = $xmlContent !== '' && $xmlSignature !== '' && $userKey !== '' && $this->signer->verify($xmlContent, $xmlSignature, $userKey);

        $spot->update([
            'description' => $parsed['description'] ?? null,
            'nzb_segments' => $parsed['nzb_segments'] ?? [],
            'image_segment' => $parsed['image_segment'] ?? null,
            'image_segments' => $parsed['image_segments'] ?? [],
            'website' => $parsed['website'] ?? null,
            'xml_signature' => $parsed['xml_signature'] ?? '',
            'poster_key_id' => $parsed['poster_key_id'] ?? null,
            'is_verified' => $isVerified,
        ]);

        return true;
    }

    /**
     * Fetch HEAD headers for a spot, retrying on connection failures.
     *
     * @return array<string, string>|null
     */
    private function fetchHead(Spot $spot): ?array
    {
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            $nntp = $this->nntpService->makeDriver(driver: 'single');

            try {
                $nntp->connect(showProgress: false);

                return $nntp->head($spot->message_id);
            } catch (\Throwable) {
                // Retry on next iteration.
            } finally {
                try {
                    $nntp->quit();
                } catch (\Throwable) {
                    // Ignore quit errors.
                }
            }
        }

        return null;
    }

    /**
     * A spot is considered enriched when its xml_signature has been set (even
     * to an empty string), or when it has non-default X-XML fields. The
     * xml_signature column is always written during enrichment, so it serves
     * as a reliable sentinel — null means "never enriched".
     */
    public function isEnriched(Spot $spot): bool
    {
        return $spot->xml_signature !== null
            || $spot->nzb_segments !== []
            || $spot->image_segments !== []
            || $spot->image_segment !== null
            || $spot->description !== null;
    }
}
