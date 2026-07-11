<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Spot;
use App\Services\Search\Contracts\SearchDriver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SearchIndexSynchronizer
{
    public function __construct(private SearchDriver $searchDriver) {}

    public function synchronizePending(int $batchSize = 500, ?int $limit = null): int
    {
        $this->assertExternalIndex();
        $this->searchDriver->ensureIndex();
        $processed = 0;

        while ($limit === null || $processed < $limit) {
            $queryLimit = $limit === null
                ? $batchSize
                : min($batchSize, $limit - $processed);

            /** @var Collection<int, object{spot_id: int, token: string}> $pending */
            $pending = DB::table('spot_search_sync_queue')
                ->select(['spot_id', 'token'])
                ->orderBy('updated_at')
                ->orderBy('spot_id')
                ->limit($queryLimit)
                ->get();

            if ($pending->isEmpty()) {
                break;
            }

            /** @var list<int> $spotIds */
            $spotIds = $pending->pluck('spot_id')->map(
                static fn (mixed $spotId): int => (int) $spotId,
            )->all();
            $spots = $this->indexableSpots()
                ->whereKey($spotIds)
                ->get();
            $existingIds = $spots->modelKeys();
            $deletedIds = array_values(array_diff($spotIds, $existingIds));

            $this->searchDriver->indexSpots($spots);
            $this->searchDriver->deleteSpots($deletedIds);

            DB::transaction(function () use ($pending): void {
                foreach ($pending as $queuedSpot) {
                    DB::table('spot_search_sync_queue')
                        ->where('spot_id', $queuedSpot->spot_id)
                        ->where('token', $queuedSpot->token)
                        ->delete();
                }
            });

            $processed += $pending->count();
        }

        return $processed;
    }

    public function rebuild(int $batchSize = 1_000): int
    {
        $this->assertExternalIndex();
        $this->searchDriver->ensureIndex();
        $this->searchDriver->truncateIndex();
        $indexed = 0;

        $this->indexableSpots()
            ->orderBy('id')
            ->chunkById($batchSize, function (EloquentCollection $spots) use (&$indexed): void {
                $this->searchDriver->indexSpots($spots);
                $indexed += $spots->count();
            });

        $this->synchronizePending($batchSize);

        return $indexed;
    }

    /**
     * @return array{database: int, index: int, missing: int}
     */
    public function parity(int $batchSize = 1_000): array
    {
        $this->assertExternalIndex();
        $this->searchDriver->ensureIndex();
        $databaseCount = Spot::query()->count();
        $indexCount = $this->searchDriver->indexedDocumentCount();
        $missing = 0;

        Spot::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById($batchSize, function (EloquentCollection $spots) use (&$missing): void {
                /** @var list<int> $databaseIds */
                $databaseIds = $spots->modelKeys();
                $indexedIds = $this->searchDriver->findIndexedIds($databaseIds);
                $missing += \count(array_diff($databaseIds, $indexedIds));
            });

        return [
            'database' => $databaseCount,
            'index' => $indexCount,
            'missing' => $missing,
        ];
    }

    public function pendingCount(): int
    {
        return DB::table('spot_search_sync_queue')->count();
    }

    /**
     * @return Builder<Spot>
     */
    private function indexableSpots(): Builder
    {
        return Spot::query()->select([
            'id',
            'title',
            'description',
            'category_code',
            'subcategories',
            'spot_posted_at',
            'file_size',
            'nzb_segments',
        ]);
    }

    private function assertExternalIndex(): void
    {
        if (! $this->searchDriver->usesExternalIndex()) {
            throw new \LogicException('SEARCH_DRIVER must be manticore for search index maintenance.');
        }
    }
}
