<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * @property string $code
 * @property string|null $parent_code
 * @property string $name
 * @property string $slug
 * @property string|null $type
 * @property int $sort_order
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 */
class Category extends Model
{
    /** @use HasFactory<\Database\Factories\CategoryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'parent_code',
        'name',
        'slug',
        'type',
        'sort_order',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_code', 'code');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_code', 'code');
    }

    #[Scope]
    protected function mainCategories(Builder $query): Builder
    {
        return $query->whereNull('parent_code');
    }

    #[Scope]
    protected function subcategoriesOf(Builder $query, string $parentCode): Builder
    {
        return $query->where('parent_code', $parentCode);
    }

    #[Scope]
    protected function ofType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Get all categories ordered for display, cached for 24 hours.
     *
     * @return Collection<int, Category>
     */
    public static function allCached(): Collection
    {
        /** @var Collection<int, Category> */
        return Cache::remember('categories.all', now()->addDay(), fn () => static::query()
            ->orderBy('parent_code')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());
    }

    /**
     * Build a hierarchical category tree, cached for 24 hours.
     *
     * @return array<string, array{category: Category, subcategories: array<string, Collection<int, Category>>}>
     */
    public static function tree(): array
    {
        /** @var array<string, array{category: Category, subcategories: array<string, Collection<int, Category>>}> */
        return Cache::remember('categories.tree', now()->addDay(), function () {
            $all = static::allCached();

            $tree = [];

            foreach ($all->whereNull('parent_code') as $main) {
                $children = $all->where('parent_code', $main->code);

                $tree[$main->code] = [
                    'category' => $main,
                    'subcategories' => [
                        'type' => $children->where('type', 'type')->where('name', '!=', '-')->values(),
                        'format' => $children->where('type', 'format')->where('name', '!=', '-')->values(),
                        'source' => $children->where('type', 'source')->where('name', '!=', '-')->values(),
                        'language' => $children->where('type', 'language')->where('name', '!=', '-')->values(),
                        'bitrate' => $children->where('type', 'bitrate')->where('name', '!=', '-')->values(),
                        'genre' => $children->where('type', 'genre')->where('name', '!=', '-')->values(),
                        'platform' => $children->where('type', 'platform')->where('name', '!=', '-')->values(),
                    ],
                ];
            }

            return $tree;
        });
    }

    /**
     * Short display label for badge rendering, preferring abbreviated format names.
     */
    public function displayLabel(): string
    {
        return self::SLUG_LABELS[$this->slug] ?? $this->name ?? $this->slug ?? '?';
    }

    public static function clearCache(): void
    {
        Cache::forget('categories.all');
        Cache::forget('categories.tree');
    }

    /** @var array<string, string> */
    private const array SLUG_LABELS = [
        'divx' => 'DivX',
        'wmv' => 'WMV',
        'wmvhd' => 'WMVHD',
        'mpg' => 'MPEG',
        'dvd5' => 'DVD5',
        'dvd9' => 'DVD9',
        'hd-other' => 'HD Oth',
        'uhd' => 'UHD',
        'x264' => 'x264',
        'hddvd' => 'HD-DVD',
        '3d' => '3D',
        'pdf' => 'PDF',
        'epub' => 'ePub',
        'bitmap' => 'Bitmap',
        'vector' => 'Vector',
        'bluray' => 'Blu-ray',
        'mp3' => 'MP3',
        'wma' => 'WMA',
        'wav' => 'WAV',
        'flac' => 'FLAC',
        'eac' => 'EAC',
        'dts' => 'DTS',
        'aac' => 'AAC',
        'ape' => 'APE',
        'ogg' => 'OGG',
        'windows' => 'Win',
        'windows-app' => 'Win',
        'mac' => 'Mac',
        'mac-app' => 'Mac',
        'linux' => 'Linux',
        'linux-app' => 'Linux',
        'os2' => 'OS/2',
        'playstation' => 'PS1',
        'playstation2' => 'PS2',
        'playstation3' => 'PS3',
        'playstation4' => 'PS4',
        'psp' => 'PSP',
        'xbox' => 'Xbox',
        'xbox360' => 'X360',
        'xboxone' => 'XB1',
        'winphone' => 'WinPh',
        'navigation' => 'Nav',
        'ios' => 'iOS',
        'android' => 'Android',
        'nds' => 'NDS',
        'wii' => 'Wii',
        'gba' => 'GBA',
        'gamecube' => 'GCN',
        '3ds' => '3DS',
    ];
}
