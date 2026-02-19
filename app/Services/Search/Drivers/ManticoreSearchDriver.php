<?php

declare(strict_types=1);

namespace App\Services\Search\Drivers;

use App\Enums\SearchField;
use App\Models\Spot;
use App\Services\Search\Contracts\SearchDriver;
use Illuminate\Database\Eloquent\Builder;

/**
 * WORK IN PROGRESS.
 *
 * Manticore Search driver stub.
 *
 * When implemented, this driver will call Manticore's HTTP API to get
 * matching spot IDs, then constrain the Eloquent query to those IDs.
 * Set SEARCH_DRIVER=manticore in .env to activate.
 */
class ManticoreSearchDriver implements SearchDriver
{
    // @phpstan-ignore-next-line
    public function __construct(private readonly string $host, private readonly int $port, private readonly string $index) {}

    /**
     * Apply a search term to an existing Spot query builder.
     */
    public function search(Builder $query, string $term, SearchField $field = SearchField::Title): Builder
    {
        // TODO: Implement search method
        return $query;
    }

    /**
     * Apply multiple OR search variants to the query (used for TV search expansion).
     *
     * @param  string[]  $variants
     */
    public function searchVariants(Builder $query, array $variants, SearchField $field = SearchField::Title): Builder
    {
        // TODO: Implement searchVariants method
        return $query;
    }

    public function indexSpot(Spot $spot): void
    {
        // TODO: POST to Manticore HTTP API to index spot
    }

    public function deleteSpot(int $id): void
    {
        // TODO: DELETE from Manticore HTTP API
    }
}
