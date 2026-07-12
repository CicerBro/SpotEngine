<?php

declare(strict_types=1);

namespace App\Services\Search\Drivers;

use App\Data\SpotSearchCriteria;
use App\Enums\SearchField;
use App\Models\Spot;
use App\Services\Search\Contracts\SearchDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * PostgreSQL FTS search driver.
 *
 * Title search uses the 'simple' dictionary (lowercase only, no stemming) so
 * that "candy" matches exactly "candy" and NOT "candyman". Explicit wildcard
 * suffix (* or %) enables prefix matching: "candy*" finds "candyman".
 *
 * Description and Both searches use the 'english' dictionary (with stemming)
 * so "run" also finds "running", "runner", etc.
 *
 * Supported websearch_to_tsquery operators (no-wildcard mode):
 *   word1 word2          → both words (AND)
 *   word1 or word2       → either word (OR)
 *   "exact phrase"       → phrase match
 *   -word                → exclude word
 *
 * Wildcard mode (* or % suffix) also supports: word*, or, -word.
 */
class DatabaseSearchDriver implements SearchDriver
{
    private const string TITLE_VEC = "to_tsvector('simple', title)";

    private const string DESC_VEC = "to_tsvector('english', COALESCE(description, ''))";

    private const string BOTH_VEC = "to_tsvector('english', title || ' ' || COALESCE(description, ''))";

    public function paginate(SpotSearchCriteria $criteria): LengthAwarePaginator
    {
        $query = $this->filteredQuery($criteria);

        if ($criteria->offset === null) {
            return $query->paginate(
                $criteria->perPage,
                ['*'],
                $criteria->pageName,
                $criteria->page,
            );
        }

        $offset = max(0, $criteria->offset);
        $total = (clone $query)->count();
        $spots = $query
            ->offset($offset)
            ->limit($criteria->perPage)
            ->get();

        return new LengthAwarePaginator(
            $spots,
            $total,
            $criteria->perPage,
            intdiv($offset, $criteria->perPage) + 1,
            [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => $criteria->pageName,
            ],
        );
    }

    public function cursorPaginate(SpotSearchCriteria $criteria): CursorPaginator
    {
        $cursor = $criteria->cursor !== null && $criteria->cursor !== ''
            ? Cursor::fromEncoded($criteria->cursor)
            : null;

        return $this->filteredQuery($criteria)
            ->cursorPaginate(
                $criteria->perPage,
                cursor: $cursor,
            );
    }

    public function count(SpotSearchCriteria $criteria): int
    {
        return $this->filteredQuery($criteria)->count();
    }

    public function search(Builder $query, string $term, SearchField $field = SearchField::Title): Builder
    {
        return match ($field) {
            SearchField::Title => $this->applyTitleSearch($query, $term),
            SearchField::Description => $this->applyDescriptionSearch($query, $term),
            SearchField::Both => $this->applyBothSearch($query, $term),
        };
    }

    public function searchVariants(Builder $query, array $variants, SearchField $field = SearchField::Title): Builder
    {
        if ($variants === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($variants, $field): void {
            foreach ($variants as $variant) {
                $q->orWhere(function (Builder $inner) use ($variant, $field): void {
                    $this->search($inner, $variant, $field);
                });
            }
        });
    }

    public function indexSpot(Spot $spot): void {}

    public function deleteSpot(int $id): void {}

    public function usesExternalIndex(): bool
    {
        return false;
    }

    public function ensureIndex(): void {}

    public function truncateIndex(): void {}

    public function indexSpots(iterable $spots): void {}

    public function deleteSpots(array $ids): void {}

    public function indexedDocumentCount(): int
    {
        return 0;
    }

    public function findIndexedIds(array $ids): array
    {
        return [];
    }

    /**
     * @return Builder<Spot>
     */
    private function filteredQuery(SpotSearchCriteria $criteria): Builder
    {
        $term = trim((string) $criteria->term);
        $query = $this->listingQuery();

        if ($criteria->category !== null && $criteria->category !== '') {
            $query->inCategory($criteria->category);
        }

        if ($criteria->subcategories !== []) {
            $query->withSubcategory($criteria->subcategories);
        }

        if ($criteria->termVariants !== []) {
            $this->searchVariants($query, $criteria->termVariants, $criteria->field);
        } elseif ($term !== '') {
            $this->search($query, $term, $criteria->field);
        }

        foreach ($criteria->metadataTermGroups as $metadataTerms) {
            $this->whereMetadataContainsAny($query, $metadataTerms);
        }

        if ($criteria->unreadOnly) {
            $query->unreadSince($criteria->unreadSince);
        }

        return $query
            ->orderByDesc('spot_posted_at')
            ->orderByDesc('id');
    }

    /**
     * @return Builder<Spot>
     */
    private function listingQuery(): Builder
    {
        return Spot::query()
            ->select(['id', 'title', 'description', 'poster', 'file_size', 'spot_posted_at', 'category_code', 'subcategories'])
            ->selectRaw("(nzb_segments <> '[]'::jsonb) AS has_nzb")
            ->with('category:code,name,slug');
    }

    /**
     * @param  list<string>  $terms
     */
    private function whereMetadataContainsAny(Builder $query, array $terms): Builder
    {
        return $query->where(function (Builder $metadataQuery) use ($terms): void {
            foreach ($terms as $term) {
                $pattern = '%' . $term . '%';

                $metadataQuery->orWhere(function (Builder $termQuery) use ($pattern): void {
                    $termQuery
                        ->whereRaw('website ILIKE ?', [$pattern])
                        ->orWhereRaw('description ILIKE ?', [$pattern])
                        ->orWhereRaw('title ILIKE ?', [$pattern]);
                });
            }
        });
    }

    /**
     * Title search against spots_fts_title_simple_index.
     * Uses 'simple' dict — "candy" is exact, "candy*" enables prefix.
     */
    private function applyTitleSearch(Builder $query, string $term): Builder
    {
        if ($this->hasWildcard($term)) {
            return $query->whereRaw(
                self::TITLE_VEC . " @@ to_tsquery('simple', ?)",
                [$this->buildWildcardQuery($term)]
            );
        }

        return $query->whereRaw(
            self::TITLE_VEC . " @@ websearch_to_tsquery('simple', ?)",
            [$term]
        );
    }

    /**
     * Description-only search against spots_fts_description_index.
     * Uses 'english' dict so stemmed forms are found ("run" → "running").
     */
    private function applyDescriptionSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            self::DESC_VEC . " @@ websearch_to_tsquery('english', ?)",
            [$this->stripWildcards($term)]
        );
    }

    /**
     * Title + description search.
     *
     * No wildcards: single scan on the existing spots_fts_title_description_index.
     * With wildcards: bitmap OR across title (simple) and description (english).
     */
    private function applyBothSearch(Builder $query, string $term): Builder
    {
        if (! $this->hasWildcard($term)) {
            return $query->whereRaw(
                self::BOTH_VEC . " @@ websearch_to_tsquery('english', ?)",
                [$term]
            );
        }

        $wildcardQuery = $this->buildWildcardQuery($term);
        $cleanTerm = $this->stripWildcards($term);

        return $query->where(function (Builder $q) use ($wildcardQuery, $cleanTerm): void {
            $q->whereRaw(self::TITLE_VEC . " @@ to_tsquery('simple', ?)", [$wildcardQuery])
                ->orWhereRaw(self::DESC_VEC . " @@ websearch_to_tsquery('english', ?)", [$cleanTerm]);
        });
    }

    private function hasWildcard(string $term): bool
    {
        return str_contains($term, '*') || str_contains($term, '%');
    }

    private function stripWildcards(string $term): string
    {
        return str_replace(['*', '%'], '', $term);
    }

    /**
     * Convert a user-typed search term containing wildcards into a to_tsquery
     * expression for the 'simple' dictionary.
     *
     * Rules:
     *   word    → exact token
     *   word*   → prefix token (word:*)
     *   word%   → prefix token (word:*)
     *   -word   → negation (!word)
     *   or      → OR operator (|)
     *   default → AND between tokens (&)
     */
    private function buildWildcardQuery(string $term): string
    {
        $words = preg_split('/\s+/', str_replace('"', ' ', trim($term)), -1, PREG_SPLIT_NO_EMPTY);

        $parts = [];
        $pendingOp = '&';

        foreach ($words as $word) {
            if (mb_strtolower($word) === 'or') {
                $pendingOp = '|';

                continue;
            }

            $negated = str_starts_with($word, '-');
            $base = $negated ? mb_substr($word, 1) : $word;
            $isPrefix = (bool) preg_match('/[*%]$/', $base);
            $clean = (string) preg_replace('/[*%&|!:<>()\'"]/u', '', $base);

            if ($clean === '') {
                $pendingOp = '&';

                continue;
            }

            $token = $clean . ($isPrefix ? ':*' : '');
            $token = $negated ? '!' . $token : $token;

            if ($parts !== []) {
                $parts[] = $pendingOp;
            }

            $parts[] = $token;
            $pendingOp = '&';
        }

        return $parts !== [] ? implode(' ', $parts) : "''";
    }
}
