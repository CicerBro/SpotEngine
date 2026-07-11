<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RootCategory;
use App\Enums\SearchField;
use App\Services\BbCodeParsingService;
use App\Services\Search\Contracts\SearchDriver;
use Carbon\CarbonImmutable;
use Database\Factories\SpotFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $message_id
 * @property string|null $poster
 * @property string|null $poster_key_id
 * @property string $title
 * @property string|null $description
 * @property string|null $tag
 * @property string|null $website
 * @property string $category_code
 * @property array<int, string> $subcategories
 * @property int $file_size
 * @property string|null $image_segment
 * @property array<int, string> $image_segments
 * @property array<int, string> $nzb_segments
 * @property CarbonImmutable $spot_posted_at
 * @property string|null $xml_signature
 * @property bool $is_verified
 * @property bool $has_nzb
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Category|null $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, UserDownload> $downloads
 * @property-read string $description_html
 * @property-read RootCategory|null $root_category
 * @property-read string $age_formatted
 * @property-read string $size_formatted
 * @property-read string $sender
 */
class Spot extends Model
{
    /** @use HasFactory<SpotFactory> */
    use HasFactory;

    private bool $hasCachedDescriptionHtml = false;

    private ?string $cachedDescriptionHtmlSource = null;

    private string $cachedDescriptionHtml = '';

    protected $fillable = [
        'message_id',
        'poster',
        'poster_key_id',
        'title',
        'description',
        'tag',
        'website',
        'category_code',
        'subcategories',
        'file_size',
        'image_segment',
        'image_segments',
        'nzb_segments',
        'spot_posted_at',
        'xml_signature',
        'is_verified',
    ];

    #[\Override]
    protected function casts(): array
    {
        return [
            'subcategories' => 'array',
            'image_segments' => 'array',
            'nzb_segments' => 'array',
            'file_size' => 'integer',
            'is_verified' => 'boolean',
            'has_nzb' => 'boolean',
            'spot_posted_at' => 'immutable_datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_code', 'code');
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(UserDownload::class);
    }

    #[Scope]
    protected function inCategory(Builder $query, string $code): Builder
    {
        return $query->where('category_code', $code);
    }

    /**
     * Filter by one or more subcategory codes using PostgreSQL JSONB containment.
     *
     * @param  string[]  $codes
     */
    #[Scope]
    protected function withSubcategory(Builder $query, array $codes): Builder
    {
        return $query->where(function (Builder $q) use ($codes) {
            foreach ($codes as $code) {
                $q->orWhereRaw('subcategories @> ?::jsonb', [json_encode([$code]) ?: '[]']);
            }
        });
    }

    #[Scope]
    protected function search(Builder $query, string $term, SearchField $field = SearchField::Title): Builder
    {
        return app(SearchDriver::class)->search($query, $term, $field);
    }

    #[Scope]
    protected function latestFirst(Builder $query): Builder
    {
        return $query->orderBy('spot_posted_at', 'desc');
    }

    #[Scope]
    protected function postedAfter(Builder $query, \DateTimeInterface $date): Builder
    {
        return $query->where('spot_posted_at', '>=', $date);
    }

    public function getAgeFormattedAttribute(): string
    {
        return $this->spot_posted_at->diffForHumans();
    }

    public function getSizeFormattedAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2).' GB';
        }

        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }

        return number_format($bytes / 1024, 0).' KB';
    }

    public function getSenderAttribute(): string
    {
        return $this->poster ?? 'Unknown';
    }

    public function getRootCategoryAttribute(): ?RootCategory
    {
        return RootCategory::tryFrom(substr($this->category_code, 0, 2));
    }

    /**
     * Resolve the most relevant subcategory for badge display.
     *
     * @param  Collection<string, Category>  $categoriesByCode
     */
    public function resolveBadgeCategory(Collection $categoriesByCode): ?Category
    {
        $rootCategory = $this->root_category;
        $subcatCodes = $this->subcategories ?? [];

        $preferredTypes = $rootCategory?->preferredBadgeTypes() ?? ['format', 'type', 'platform'];

        foreach ($preferredTypes as $preferredType) {
            foreach ($subcatCodes as $subcatCode) {
                $candidate = $this->resolveSubcategory($categoriesByCode, $subcatCode);
                if ($candidate && $candidate->type === $preferredType && $candidate->name !== '-') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Resolve the genre label from subcategories.
     *
     * @param  Collection<string, Category>  $categoriesByCode
     */
    public function resolveGenreLabel(Collection $categoriesByCode): ?string
    {
        foreach ($this->subcategories ?? [] as $subcatCode) {
            $candidate = $this->resolveSubcategory($categoriesByCode, $subcatCode);
            if ($candidate && $candidate->type === 'genre' && $candidate->name !== '-') {
                return $candidate->name;
            }
        }

        return null;
    }

    /**
     * Resolve a subcategory using both canonical (01a09) and legacy short (a9) code formats.
     *
     * @param  Collection<string, Category>  $categoriesByCode
     */
    public function resolveSubcategory(Collection $categoriesByCode, string $subcategoryCode): ?Category
    {
        foreach ($this->subcategoryLookupCodes($subcategoryCode) as $lookupCode) {
            $candidate = $categoriesByCode->get($lookupCode);

            if ($candidate instanceof Category) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build lookup candidates for a subcategory code in both storage formats.
     *
     * @return list<string>
     */
    private function subcategoryLookupCodes(string $subcategoryCode): array
    {
        $normalizedCode = strtolower(trim($subcategoryCode));

        if ($normalizedCode === '') {
            return [];
        }

        $lookupCodes = [$normalizedCode];

        if (preg_match('/^([a-z])(\d{1,2})$/', $normalizedCode, $matches) === 1) {
            $lookupCodes[] = $this->rootCategoryCode().$matches[1].sprintf('%02d', (int) $matches[2]);
        }

        if (preg_match('/^(\d{2})([a-z])(\d{1,2})$/', $normalizedCode, $matches) === 1) {
            $lookupCodes[] = $matches[1].$matches[2].sprintf('%02d', (int) $matches[3]);
        }

        return array_values(array_unique($lookupCodes));
    }

    /**
     * Resolve the two-digit head category code used as the canonical subcategory prefix.
     */
    private function rootCategoryCode(): string
    {
        if (preg_match('/^(\d{2})/', $this->category_code, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(\d)$/', $this->category_code, $matches) === 1) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        }

        return '01';
    }

    public function getDescriptionHtmlAttribute(): string
    {
        $description = $this->description;

        if (! $this->hasCachedDescriptionHtml || $this->cachedDescriptionHtmlSource !== $description) {
            $this->cachedDescriptionHtml = app(BbCodeParsingService::class)->parse($description);
            $this->cachedDescriptionHtmlSource = $description;
            $this->hasCachedDescriptionHtml = true;
        }

        return $this->cachedDescriptionHtml;
    }
}
