<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

use App\Data\SpotSearchCriteria;
use App\Enums\SearchField;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

interface SearchDriver
{
    /**
     * Execute the complete listing query, including filters, sorting, and pagination.
     *
     * @return LengthAwarePaginator<int, Spot>
     */
    public function paginate(SpotSearchCriteria $criteria): LengthAwarePaginator;

    /**
     * Apply a search term to an existing Spot query builder.
     */
    public function search(Builder $query, string $term, SearchField $field = SearchField::Title): Builder;

    /**
     * Apply multiple OR search variants to the query (used for TV search expansion).
     *
     * @param  string[]  $variants
     */
    public function searchVariants(Builder $query, array $variants, SearchField $field = SearchField::Title): Builder;

    /**
     * Push a new or updated spot into the search index (no-op for DB driver).
     */
    public function indexSpot(Spot $spot): void;

    /**
     * Remove a spot from the search index (no-op for DB driver).
     */
    public function deleteSpot(int $id): void;

    public function usesExternalIndex(): bool;

    public function ensureIndex(): void;

    public function truncateIndex(): void;

    /**
     * @param  iterable<Spot>  $spots
     */
    public function indexSpots(iterable $spots): void;

    /**
     * @param  list<int>  $ids
     */
    public function deleteSpots(array $ids): void;

    public function indexedDocumentCount(): int;

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function findIndexedIds(array $ids): array;
}
