<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\SpotBan;
use App\Services\Nntp\SpotterId;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class SpotwebListImporter
{
    /**
     * @return array{total: int, imported: int, skipped: int}
     */
    public function import(SpotListKind $kind, ?string $url = null): array
    {
        $url ??= (string) config("spotengine.lists.{$kind->value}_url");

        if ($url === '') {
            throw new RuntimeException("Spotweb {$kind->value} URL is not configured.");
        }

        $response = Http::timeout(30)
            ->withUserAgent('SpotEngine/1.0')
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download {$kind->value} (HTTP {$response->status()}).");
        }

        return $this->importFromXml($kind, $response->body());
    }

    /**
     * @return array{total: int, imported: int, skipped: int}
     */
    public function importFromXml(SpotListKind $kind, string $xml): array
    {
        $document = simplexml_load_string($xml);

        if (! $document instanceof SimpleXMLElement) {
            throw new RuntimeException("Failed to parse Spotweb {$kind->value} XML.");
        }

        $total = 0;
        $imported = 0;
        $skipped = 0;
        $seenSpotterIds = [];

        foreach ($document->Key as $keyElement) {
            $total++;

            $spotterId = SpotterId::fromModulus((string) $keyElement);

            if ($spotterId === null || isset($seenSpotterIds[$spotterId])) {
                $skipped++;

                continue;
            }

            $seenSpotterIds[$spotterId] = true;

            $ban = SpotBan::query()->firstOrCreate([
                'kind' => $kind,
                'type' => SpotBanType::PosterKeyId,
                'value' => SpotBan::normalizeValue(SpotBanType::PosterKeyId, $spotterId),
            ]);

            $name = SpotBan::normalizeName((string) ($keyElement['Name'] ?? ''));

            if ($name !== null && $ban->name !== $name) {
                $ban->name = $name;
                $ban->save();
            }

            if ($ban->wasRecentlyCreated) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        SpotBanService::clearCache();

        return [
            'total' => $total,
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }
}
