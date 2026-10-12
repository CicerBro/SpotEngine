<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\SpotBan;
use Illuminate\Support\Facades\Cache;

class SpotBanService
{
    /** @var array<string, array<string, true>>|null */
    private ?array $batchLists = null;

    public static function clearCache(): void
    {
        foreach (SpotListKind::cases() as $kind) {
            foreach (SpotBanType::cases() as $type) {
                Cache::memo()->forget(self::cacheKey($kind, $type));
            }
        }
    }

    public function beginBatch(): void
    {
        $this->batchLists = [];

        foreach (SpotListKind::cases() as $kind) {
            foreach (SpotBanType::cases() as $type) {
                $this->batchLists[self::cacheKey($kind, $type)] = $this->loadValues($kind, $type, false);
            }
        }
    }

    public function endBatch(): void
    {
        $this->batchLists = null;
    }

    public function isBanned(?string $poster, ?string $tag, ?string $posterKeyId = null): bool
    {
        if ($posterKeyId !== null && $posterKeyId !== '' && $this->matches(SpotListKind::Whitelist, SpotBanType::PosterKeyId, $posterKeyId)) {
            return false;
        }

        if ($tag !== null && $tag !== '' && $this->matches(SpotListKind::Blacklist, SpotBanType::Tag, $tag)) {
            return true;
        }

        if ($poster !== null && $poster !== '' && $this->matches(SpotListKind::Blacklist, SpotBanType::Poster, $poster)) {
            return true;
        }

        if ($posterKeyId !== null && $posterKeyId !== '' && $this->matches(SpotListKind::Blacklist, SpotBanType::PosterKeyId, $posterKeyId)) {
            return true;
        }

        return false;
    }

    private static function cacheKey(SpotListKind $kind, SpotBanType $type): string
    {
        return 'spot_bans.sets.' . $kind->value . '.' . $type->value;
    }

    private function matches(SpotListKind $kind, SpotBanType $type, string $value): bool
    {
        $normalized = match ($type) {
            SpotBanType::Poster, SpotBanType::Tag => SpotBan::normalizeValue($type, $value),
            SpotBanType::PosterKeyId => trim($value),
        };

        return isset($this->listValues($kind, $type)[$normalized]);
    }

    /**
     * @return array<string, true>
     */
    private function listValues(SpotListKind $kind, SpotBanType $type): array
    {
        return $this->batchLists[self::cacheKey($kind, $type)] ?? $this->loadValues($kind, $type, true);
    }

    /** @return array<string, true> */
    private function loadValues(SpotListKind $kind, SpotBanType $type, bool $memoize): array
    {
        /** @var array<string, true> */
        return ($memoize ? Cache::memo() : Cache::store())->remember(
            self::cacheKey($kind, $type),
            now()->addDay(),
            fn () => array_fill_keys(SpotBan::query()
                ->where('kind', $kind)
                ->where('type', $type)
                ->pluck('value')
                ->all(), true),
        );
    }
}
