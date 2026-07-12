<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\SpotBan;
use Illuminate\Support\Facades\Cache;

class SpotBanService
{
    public static function clearCache(): void
    {
        foreach (SpotListKind::cases() as $kind) {
            foreach (SpotBanType::cases() as $type) {
                Cache::forget(self::cacheKey($kind, $type));
            }
        }
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
        return 'spot_bans.' . $kind->value . '.' . $type->value;
    }

    private function matches(SpotListKind $kind, SpotBanType $type, string $value): bool
    {
        $normalized = match ($type) {
            SpotBanType::Poster, SpotBanType::Tag => SpotBan::normalizeValue($type, $value),
            SpotBanType::PosterKeyId => trim($value),
        };

        return in_array($normalized, $this->listValues($kind, $type), true);
    }

    /**
     * @return list<string>
     */
    private function listValues(SpotListKind $kind, SpotBanType $type): array
    {
        /** @var list<string> */
        return Cache::remember(
            self::cacheKey($kind, $type),
            now()->addDay(),
            fn () => SpotBan::query()
                ->where('kind', $kind)
                ->where('type', $type)
                ->pluck('value')
                ->all(),
        );
    }
}
