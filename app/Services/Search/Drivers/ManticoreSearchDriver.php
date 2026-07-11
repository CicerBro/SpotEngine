<?php

declare(strict_types=1);

namespace App\Services\Search\Drivers;

use App\Data\SpotSearchCriteria;
use App\Enums\SearchField;
use App\Exceptions\ManticoreSearchException;
use App\Models\Spot;
use App\Services\Search\Contracts\SearchDriver;
use App\Services\Search\ManticoreDocumentMapper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ManticoreSearchDriver implements SearchDriver
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $index,
        private readonly ManticoreDocumentMapper $documentMapper,
        private readonly string $scheme = 'http',
        private readonly int $timeout = 5,
        private readonly int $maxMatches = 100_000,
    ) {
        $this->validateConfiguration();
    }

    public function paginate(SpotSearchCriteria $criteria): LengthAwarePaginator
    {
        $offset = max(0, $criteria->offset ?? (($criteria->page - 1) * $criteria->perPage));
        $payload = [
            'table' => $this->index,
            'query' => $this->buildQuery($criteria),
            '_source' => [],
            'sort' => [
                ['posted_at' => 'desc'],
                ['id' => 'desc'],
            ],
            'offset' => $offset,
            'limit' => $criteria->perPage,
            'options' => [
                'max_matches' => max($this->maxMatches, $offset + $criteria->perPage),
            ],
        ];

        $response = $this->jsonRequest('/search', $payload);
        $hits = $response['hits'] ?? null;

        if (! \is_array($hits) || ! \is_array($hits['hits'] ?? null) || ! \is_int($hits['total'] ?? null)) {
            throw new ManticoreSearchException('Manticore returned an invalid search response.');
        }

        if (($hits['total_relation'] ?? 'eq') !== 'eq') {
            throw new ManticoreSearchException(
                'Manticore could not return an exact result count. Increase MANTICORE_MAX_MATCHES.',
            );
        }

        $ids = $this->extractHitIds($hits['hits']);
        $spots = $this->hydrateListingSpots($ids);

        if ($spots->count() !== \count($ids)) {
            throw new ManticoreSearchException(
                'The Manticore index is out of sync with the spots table. Run php artisan spot:search-sync or spot:search-rebuild.',
            );
        }

        return new LengthAwarePaginator(
            $spots,
            $hits['total'],
            $criteria->perPage,
            intdiv($offset, $criteria->perPage) + 1,
            [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => $criteria->pageName,
            ],
        );
    }

    public function cursorPaginate(SpotSearchCriteria $criteria): CursorPaginator
    {
        $cursor = $criteria->cursor !== null && $criteria->cursor !== ''
            ? Cursor::fromEncoded($criteria->cursor)
            : null;

        $payload = [
            'table' => $this->index,
            'query' => $this->buildQuery($criteria, $cursor),
            '_source' => [],
            'sort' => [
                ['posted_at' => 'desc'],
                ['id' => 'desc'],
            ],
            'limit' => $criteria->perPage + 1,
            'options' => [
                'max_matches' => $this->maxMatches,
            ],
        ];

        $response = $this->jsonRequest('/search', $payload);
        $hits = $response['hits'] ?? null;

        if (! \is_array($hits) || ! \is_array($hits['hits'] ?? null)) {
            throw new ManticoreSearchException('Manticore returned an invalid search response.');
        }

        $ids = $this->extractHitIds($hits['hits']);
        $spots = $this->hydrateListingSpots($ids);

        if ($spots->count() !== \count($ids)) {
            throw new ManticoreSearchException(
                'The Manticore index is out of sync with the spots table. Run php artisan spot:search-sync or spot:search-rebuild.',
            );
        }

        return new CursorPaginator(
            $spots,
            $criteria->perPage,
            $cursor,
            [
                'path' => Paginator::resolveCurrentPath(),
                'cursorName' => 'cursor',
                'parameters' => ['spot_posted_at', 'id'],
            ]
        );
    }

    public function count(SpotSearchCriteria $criteria): int
    {
        $response = $this->jsonRequest('/search', [
            'table' => $this->index,
            'query' => $this->buildQuery($criteria),
            '_source' => [],
            'limit' => 0,
            'options' => [
                'max_matches' => $this->maxMatches,
            ],
        ]);
        $hits = $response['hits'] ?? null;

        if (! \is_array($hits) || ! \is_int($hits['total'] ?? null)) {
            throw new ManticoreSearchException('Manticore returned an invalid search response.');
        }

        if (($hits['total_relation'] ?? 'eq') !== 'eq') {
            throw new ManticoreSearchException(
                'Manticore could not return an exact result count. Increase MANTICORE_MAX_MATCHES.',
            );
        }

        return $hits['total'];
    }

    public function search(Builder $query, string $term, SearchField $field = SearchField::Title): Builder
    {
        throw new ManticoreSearchException(
            'Manticore searches must use the query-engine paginator; a database builder cannot represent external search results.',
        );
    }

    public function searchVariants(Builder $query, array $variants, SearchField $field = SearchField::Title): Builder
    {
        throw new ManticoreSearchException(
            'Manticore variant searches must use the query engine instead of an Eloquent builder.',
        );
    }

    public function indexSpot(Spot $spot): void
    {
        $this->indexSpots([$spot]);
    }

    public function deleteSpot(int $id): void
    {
        $this->deleteSpots([$id]);
    }

    public function usesExternalIndex(): bool
    {
        return true;
    }

    public function ensureIndex(): void
    {
        $this->rawSql(
            "CREATE TABLE IF NOT EXISTS `{$this->index}` (" .
            'title text, description text, category int, subcategories multi64, ' .
            'posted_at timestamp, file_size bigint, has_nzb bool' .
            ") min_infix_len='2'",
        );

        $this->rawSql(
            'SELECT id, title, description, category, subcategories, posted_at, file_size, has_nzb ' .
            "FROM `{$this->index}` LIMIT 0",
        );
    }

    public function truncateIndex(): void
    {
        $this->rawSql("TRUNCATE TABLE `{$this->index}`");
    }

    public function indexSpots(iterable $spots): void
    {
        $lines = [];

        foreach ($spots as $spot) {
            $lines[] = json_encode([
                'replace' => [
                    'table' => $this->index,
                    'id' => $spot->id,
                    'doc' => $this->documentMapper->map($spot),
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $this->bulkRequest($lines);
    }

    public function deleteSpots(array $ids): void
    {
        $lines = array_map(
            fn (int $id): string => json_encode([
                'delete' => [
                    'table' => $this->index,
                    'id' => $id,
                ],
            ], JSON_THROW_ON_ERROR),
            $ids,
        );

        $this->bulkRequest($lines);
    }

    public function indexedDocumentCount(): int
    {
        $response = $this->rawSqlResult(
            "SELECT COUNT(*) AS document_count FROM `{$this->index}`",
        );
        $total = $response[0]['data'][0]['document_count'] ?? null;

        if (! \is_int($total) && (!\is_string($total) || !ctype_digit($total))) {
            throw new ManticoreSearchException('Manticore returned an invalid document count.');
        }

        return (int) $total;
    }

    public function findIndexedIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $response = $this->jsonRequest('/search', [
            'table' => $this->index,
            'query' => ['in' => ['id' => $ids]],
            '_source' => [],
            'sort' => [['id' => 'asc']],
            'limit' => \count($ids),
            'options' => ['max_matches' => max($this->maxMatches, \count($ids))],
        ]);
        $hits = $response['hits']['hits'] ?? null;

        if (! \is_array($hits)) {
            throw new ManticoreSearchException('Manticore returned an invalid ID lookup response.');
        }

        return $this->extractHitIds($hits);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuery(SpotSearchCriteria $criteria, ?Cursor $cursor = null): array
    {
        $must = [];
        $term = trim((string) $criteria->term);

        if ($cursor instanceof Cursor) {
            $must[] = $this->cursorKeysetFilter($cursor);
        }

        if ($criteria->termVariants !== []) {
            $must[] = $this->matchAny($criteria->termVariants, $criteria->field);
        } elseif ($term !== '') {
            $field = match ($criteria->field) {
                SearchField::Title => 'title',
                SearchField::Description => 'description',
                SearchField::Both => 'title,description',
            };
            $must[] = ['match' => [$field => $term]];
        }

        foreach ($criteria->metadataTermGroups as $metadataTerms) {
            $must[] = ['in' => [
                'id' => $this->resolveMetadataIds($metadataTerms),
            ]];
        }

        if ($criteria->category !== null && $criteria->category !== '') {
            $must[] = ['equals' => [
                'category' => $this->documentMapper->category($criteria->category),
            ]];
        }

        if ($criteria->subcategories !== []) {
            $must[] = ['in' => [
                'subcategories' => array_map(
                    $this->documentMapper->subcategory(...),
                    $criteria->subcategories,
                ),
            ]];
        }

        return $must === []
            ? ['match_all' => (object) []]
            : ['bool' => ['must' => $must]];
    }

    /**
     * @return array{bool: array{should: list<array<string, mixed>>, minimum_should_match: int}}
     */
    private function cursorKeysetFilter(Cursor $cursor): array
    {
        $postedAt = $cursor->parameter('spot_posted_at');
        $id = (int) $cursor->parameter('id');

        $timestamp = match (true) {
            is_numeric($postedAt) => (int) $postedAt,
            is_string($postedAt) => strtotime($postedAt) ?: 0,
            default => 0,
        };

        return [
            'bool' => [
                'should' => [
                    ['range' => ['posted_at' => ['lt' => $timestamp]]],
                    [
                        'bool' => [
                            'must' => [
                                ['equals' => ['posted_at' => $timestamp]],
                                ['range' => ['id' => ['lt' => $id]]],
                            ],
                        ],
                    ],
                ],
                'minimum_should_match' => 1,
            ],
        ];
    }

    /**
     * @param  list<string>  $terms
     * @return array{bool: array{should: list<array{match: array<string, string>}>}}
     */
    private function matchAny(array $terms, SearchField $field): array
    {
        $fieldName = match ($field) {
            SearchField::Title => 'title',
            SearchField::Description => 'description',
            SearchField::Both => 'title,description',
        };

        return [
            'bool' => [
                'should' => array_map(
                    static fn (string $term): array => ['match' => [$fieldName => $term]],
                    $terms,
                ),
            ],
        ];
    }

    /**
     * Resolve external identifiers from PostgreSQL source metadata before
     * asking Manticore to filter and sort the matching document IDs.
     *
     * @param  list<string>  $terms
     * @return list<int>
     */
    private function resolveMetadataIds(array $terms): array
    {
        $ids = Spot::query()
            ->where(function (Builder $metadataQuery) use ($terms): void {
                foreach ($terms as $term) {
                    $pattern = '%' . $term . '%';

                    $metadataQuery->orWhere(function (Builder $termQuery) use ($pattern): void {
                        $termQuery
                            ->whereRaw('website ILIKE ?', [$pattern])
                            ->orWhereRaw('description ILIKE ?', [$pattern])
                            ->orWhereRaw('title ILIKE ?', [$pattern]);
                    });
                }
            })
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids !== [] ? $ids : [0];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Spot>
     */
    private function hydrateListingSpots(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        /** @var Collection<int, Spot> $spotsById */
        $spotsById = Spot::query()
            ->select(['id', 'title', 'description', 'poster', 'file_size', 'spot_posted_at', 'category_code', 'subcategories'])
            ->selectRaw("(nzb_segments <> '[]'::jsonb) AS has_nzb")
            ->with('category:code,name,slug')
            ->whereKey($ids)
            ->get()
            ->keyBy('id');

        return collect($ids)
            ->map(fn (int $id): ?Spot => $spotsById->get($id))
            ->filter()
            ->values();
    }

    /**
     * @param  array<int, mixed>  $hits
     * @return list<int>
     */
    private function extractHitIds(array $hits): array
    {
        $ids = [];

        foreach ($hits as $hit) {
            $id = \is_array($hit) ? ($hit['_id'] ?? null) : null;

            if (! \is_int($id) && (!\is_string($id) || !ctype_digit($id))) {
                throw new ManticoreSearchException('Manticore returned a hit with an invalid document ID.');
            }

            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function jsonRequest(string $path, array $payload): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->post($path, $payload);
        } catch (ConnectionException $exception) {
            throw new ManticoreSearchException("Manticore is unavailable at {$this->baseUrl()}. Check MANTICORE_HOST and MANTICORE_PORT.", $exception->getCode(), previous: $exception);
        }

        if (! $response->successful()) {
            throw new ManticoreSearchException(
                "Manticore request failed with HTTP {$response->status()}: {$response->body()}",
            );
        }

        $decoded = $response->json();

        if (! \is_array($decoded)) {
            throw new ManticoreSearchException('Manticore returned a non-JSON response.');
        }

        if (isset($decoded['error'])) {
            throw new ManticoreSearchException('Manticore query failed: ' . $decoded['error']);
        }

        return $decoded;
    }

    private function rawSql(string $sql): void
    {
        $this->rawSqlResult($sql);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rawSqlResult(string $sql): array
    {
        try {
            $response = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->withBody($sql, 'text/plain')
                ->post('/sql?mode=raw');
        } catch (ConnectionException $exception) {
            throw new ManticoreSearchException("Manticore is unavailable at {$this->baseUrl()}. Check MANTICORE_HOST and MANTICORE_PORT.", $exception->getCode(), previous: $exception);
        }

        if (! $response->successful() || $this->responseHasError($response->body())) {
            throw new ManticoreSearchException(
                "Manticore schema command failed: {$response->body()}",
            );
        }

        $decoded = $response->json();

        if (! \is_array($decoded)) {
            throw new ManticoreSearchException('Manticore returned an invalid SQL response.');
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $lines
     */
    private function bulkRequest(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        try {
            $response = Http::baseUrl($this->baseUrl())
                ->acceptJson()
                ->connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->withBody(implode("\n", $lines) . "\n", 'application/x-ndjson')
                ->post('/bulk');
        } catch (ConnectionException $exception) {
            throw new ManticoreSearchException("Manticore is unavailable at {$this->baseUrl()}; pending synchronization was preserved.", $exception->getCode(), previous: $exception);
        }

        if (! $response->successful() || $this->responseHasError($response->body())) {
            throw new ManticoreSearchException(
                "Manticore bulk synchronization failed; pending synchronization was preserved: {$response->body()}",
            );
        }
    }

    private function validateConfiguration(): void
    {
        if (trim($this->host) === '') {
            throw new ManticoreSearchException('Manticore is selected but MANTICORE_HOST is empty.');
        }

        if (! in_array($this->scheme, ['http', 'https'], true)) {
            throw new ManticoreSearchException('MANTICORE_SCHEME must be http or https.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new ManticoreSearchException('MANTICORE_PORT must be between 1 and 65535.');
        }

        if (preg_match('/^[A-Za-z_]\w*$/', $this->index) !== 1) {
            throw new ManticoreSearchException('MANTICORE_INDEX must be a valid unqualified table name.');
        }

        if ($this->timeout < 1 || $this->maxMatches < 1) {
            throw new ManticoreSearchException('Manticore timeout and max matches must be positive integers.');
        }
    }

    private function baseUrl(): string
    {
        return "{$this->scheme}://{$this->host}:{$this->port}";
    }

    private function responseHasError(string $body): bool
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            return \is_array($decoded) && $this->containsError($decoded);
        } catch (\JsonException) {
            foreach (preg_split('/\R/', trim($body)) ?: [] as $line) {
                try {
                    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return true;
                }

                if (\is_array($decoded) && $this->containsError($decoded)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function containsError(array $data): bool
    {
        foreach ($data as $key => $value) {
            if ($key === 'error' && \is_string($value) && $value !== '') {
                return true;
            }

            if ($key === 'errors' && $value === true) {
                return true;
            }

            if (\is_array($value) && $this->containsError($value)) {
                return true;
            }
        }

        return false;
    }
}
