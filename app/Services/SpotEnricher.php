<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\Nntp\SpotParser;

/**
 * Lazily enriches spots that were indexed via XOVER with their full X-XML data.
 *
 * XOVER-indexed spots have description=null, nzb_segments=[], image_segments=[].
 * This service fetches the HEAD for the spot's message-ID and populates those
 * fields from the X-XML header, then persists the result to the database.
 *
 * The cached driver is intentionally scoped to this transient service instance.
 * It avoids reconnecting when one CLI or HTTP operation enriches several spots,
 * but is not a cross-request or Octane worker connection pool.
 */
class SpotEnricher
{
    private const int MAX_RETRIES = 2;

    /** Connection reuse is limited to this SpotEnricher instance. */
    private ?SingleNntpDriver $driver = null;

    public function __construct(
        private readonly NntpService $nntpService,
        private readonly SpotParser $parser,
        private readonly SigningService $signer,
        private readonly SpotMutationService $spotMutations,
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
            $this->spotMutations->update($spot, ['xml_signature' => '']);

            return false;
        }

        $parsed = $this->parser->parseFromHeaders($headers);

        if ($parsed === null) {
            $this->spotMutations->update($spot, ['xml_signature' => '']);

            return false;
        }

        $xmlContent = $headers['x-xml'] ?? '';
        $xmlSignature = $headers['x-xml-signature'] ?? '';
        $userKey = $headers['x-user-key'] ?? '';

        $isVerified = $xmlContent !== '' && $xmlSignature !== '' && $userKey !== '' && $this->signer->verify($xmlContent, $xmlSignature, $userKey);

        $this->spotMutations->update($spot, [
            'description' => $parsed['description'] ?? null,
            'image_segments' => $parsed['image_segments'] ?? [],
            'nzb_segments' => $parsed['nzb_segments'] ?? [],
            'website' => $parsed['website'] ?? null,
            'xml_signature' => $parsed['xml_signature'] ?? '',
            'poster_key_id' => $parsed['poster_key_id'] ?? null,
            'is_verified' => $isVerified,
        ]);

        return true;
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
            || $spot->description !== null;
    }

    /**
     * Fetch HEAD headers for a spot, retrying on connection failures.
     *
     * @return array<string, string>|null
     */
    private function fetchHead(Spot $spot): ?array
    {
        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return $this->driver()->head($spot->message_id);
            } catch (\Throwable) {
                $this->disconnectDriver();
            }
        }

        return null;
    }

    private function driver(): SingleNntpDriver
    {
        if ($this->driver instanceof SingleNntpDriver && $this->driver->isConnected()) {
            return $this->driver;
        }

        $this->disconnectDriver();

        $driver = $this->nntpService->makeDriver(driver: 'single');

        if (! $driver instanceof SingleNntpDriver) {
            throw new \RuntimeException('Expected SingleNntpDriver for lazy enrichment.');
        }

        $driver->connect(showProgress: false);

        $config = $this->nntpService->getConfig();

        if (isset($config['groups']['spots'])) {
            $driver->group((string) $config['groups']['spots']);
        }

        return $this->driver = $driver;
    }

    private function disconnectDriver(): void
    {
        if ($this->driver === null) {
            return;
        }

        try {
            $this->driver->quit();
        } catch (\Throwable) {
            // Ignore quit errors during reconnect.
        }

        $this->driver = null;
    }
}
