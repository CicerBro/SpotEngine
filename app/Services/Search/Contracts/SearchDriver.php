<?php

declare(strict_types=1);

namespace App\Services\Search\Contracts;

use App\Enums\SearchField;
use App\Models\Spot;
use Illuminate\Database\Eloquent\Builder;

interface SearchDriver
{
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
}
