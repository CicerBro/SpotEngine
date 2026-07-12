<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Services\SpotBanService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property SpotListKind $kind
 * @property SpotBanType $type
 * @property string|null $name
 * @property string $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'kind',
    'type',
    'name',
    'value',
])]
class SpotBan extends Model
{
    /** @var array<string, string> */
    protected $attributes = [
        'kind' => 'blacklist',
    ];

    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 255);
    }

    public static function normalizeValue(SpotBanType $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            SpotBanType::Poster, SpotBanType::Tag => mb_strtolower($value),
            SpotBanType::PosterKeyId => $value,
        };
    }

    #[\Override]
    protected static function booted(): void
    {
        static::saving(static function (SpotBan $ban): void {
            if ($ban->type instanceof SpotBanType) {
                $ban->value = self::normalizeValue($ban->type, $ban->value);
            }

            $ban->name = self::normalizeName($ban->name);
        });

        static::saved(static fn () => SpotBanService::clearCache());
        static::deleted(static fn () => SpotBanService::clearCache());
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'kind' => SpotListKind::class,
            'type' => SpotBanType::class,
        ];
    }
}
