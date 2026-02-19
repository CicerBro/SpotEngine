<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RootCategory;
use App\Enums\SearchField;
use App\Services\BbCodeParsingService;
use App\Services\Search\Contracts\SearchDriver;
use Carbon\CarbonImmutable;
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
 * @property array<int, string> $nzb_segments
 * @property CarbonImmutable $spot_posted_at
 * @property string|null $xml_signature
 * @property bool $is_verified
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
    /** @use HasFactory<\Database\Factories\SpotFactory> */
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
            'nzb_segments' => 'array',
            'file_size' => 'integer',
            'is_verified' => 'boolean',
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
                $candidate = $categoriesByCode->get($subcatCode);
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
            $candidate = $categoriesByCode->get($subcatCode);
            if ($candidate && $candidate->type === 'genre' && $candidate->name !== '-') {
                return $candidate->name;
            }
        }

        return null;
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
