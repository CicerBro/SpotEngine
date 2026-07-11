<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SpotMutationService
{
    public function __construct(private readonly ListingCacheService $listingCache) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $updateColumns
     */
    public function upsert(array $rows, array $uniqueBy, array $updateColumns): int
    {
        if ($rows === []) {
            return 0;
        }

        [$affected, $spotIds] = DB::transaction(function () use ($rows, $uniqueBy, $updateColumns): array {
            $affected = Spot::upsert($rows, $uniqueBy, $updateColumns);
            $spotIds = $this->resolveSpotIds($rows);
            $this->queueIndexSynchronization($spotIds);

            return [$affected, $spotIds];
        });

        if ($affected > 0 || $spotIds !== []) {
            $this->listingCache->flush();
        }

        return $affected;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Spot $spot, array $attributes): bool
    {
        $updated = DB::transaction(function () use ($spot, $attributes): bool {
            $updated = $spot->update($attributes);

            if ($updated) {
                $this->queueIndexSynchronization([$spot->id]);
            }

            return $updated;
        });

        if ($updated) {
            $this->listingCache->flush();
        }

        return $updated;
    }

    /**
     * @param  list<int>  $spotIds
     */
    public function delete(array $spotIds): int
    {
        $spotIds = array_values(array_unique(array_filter(
            $spotIds,
            static fn (int $spotId): bool => $spotId > 0,
        )));

        if ($spotIds === []) {
            return 0;
        }

        $deleted = DB::transaction(function () use ($spotIds): int {
            $deleted = Spot::query()->whereKey($spotIds)->delete();
            $this->queueIndexSynchronization($spotIds);

            return $deleted;
        });

        if ($deleted > 0) {
            $this->listingCache->flush();
        }

        return $deleted;
    }

    public function deleteOlderThan(\DateTimeInterface $cutoff, int $batchSize = 1_000): int
    {
        $deleted = 0;

        while (true) {
            /** @var list<int> $spotIds */
            $spotIds = Spot::query()
                ->where('spot_posted_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id')
                ->all();

            if ($spotIds === []) {
                break;
            }

            $deleted += $this->delete($spotIds);
        }

        return $deleted;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function resolveSpotIds(array $rows): array
    {
        $ids = [];
        $messageIds = [];

        foreach ($rows as $row) {
            if (isset($row['id']) && \is_int($row['id'])) {
                $ids[] = $row['id'];
            }

            if (isset($row['message_id']) && \is_string($row['message_id']) && $row['message_id'] !== '') {
                $messageIds[] = $row['message_id'];
            }
        }

        if ($messageIds !== []) {
            $ids = [
                ...$ids,
                ...Spot::query()
                    ->whereIn('message_id', array_values(array_unique($messageIds)))
                    ->pluck('id')
                    ->all(),
            ];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        )));
    }

    /**
     * @param  list<int>  $spotIds
     */
    private function queueIndexSynchronization(array $spotIds): void
    {
        if (config('search.driver') !== 'manticore' || $spotIds === []) {
            return;
        }

        $updatedAt = now();
        $rows = array_map(
            static fn (int $spotId): array => [
                'spot_id' => $spotId,
                'token' => (string) Str::uuid(),
                'updated_at' => $updatedAt,
            ],
            $spotIds,
        );

        DB::table('spot_search_sync_queue')->upsert(
            $rows,
            ['spot_id'],
            ['token', 'updated_at'],
        );
    }
}
