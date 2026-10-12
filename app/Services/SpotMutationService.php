<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Spot;
use App\Services\Search\ExternalIdentifierNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SpotMutationService
{
    public function __construct(private ListingCacheService $listingCache) {}

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
            $this->refreshIdentifiers($spotIds, false);

            return [$affected, $spotIds];
        });

        if ($affected > 0 || $spotIds !== []) {
            $this->listingCache->flush();
        }

        return $affected;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertOrIgnore(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        [$affected, $spotIds] = DB::transaction(function () use ($rows): array {
            $affected = DB::table('spots')->insertOrIgnore($rows);
            $spotIds = $affected > 0 ? $this->resolveSpotIds($rows) : [];
            $this->refreshIdentifiers($spotIds, false);

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
                if (array_intersect(['title', 'description', 'website'], array_keys($attributes)) !== []) {
                    $this->refreshIdentifiers([$spot->id], false);
                } elseif ($this->shouldQueueIndexSynchronization()) {
                    $this->queueIndexSynchronization([$spot->id]);
                }
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

            if ($deleted > 0 && $this->shouldQueueIndexSynchronization()) {
                $this->queueIndexSynchronization($spotIds);
            }

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
     * @param  list<int>  $spotIds
     */
    public function refreshIdentifiers(array $spotIds, bool $flushCache = true): void
    {
        if ($spotIds === []) {
            return;
        }

        DB::transaction(function () use ($spotIds): void {
            foreach (array_chunk($spotIds, 500) as $chunk) {
                $spots = DB::table('spots')->select(['id', 'title', 'description', 'website'])
                    ->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get();
                $values = [];
                $bindings = [];

                foreach ($spots as $spot) {
                    $values[] = '(?::bigint, ?::jsonb)';
                    $bindings[] = $spot->id;
                    $bindings[] = json_encode(
                        (new ExternalIdentifierNormalizer)->extract($spot->title, $spot->description, $spot->website),
                        JSON_THROW_ON_ERROR,
                    );
                }

                if ($values !== []) {
                    DB::update(
                        'UPDATE spots SET external_identifiers = normalized.identifiers FROM (VALUES ' .
                        implode(', ', $values) . ') AS normalized(id, identifiers) WHERE spots.id = normalized.id',
                        $bindings,
                    );
                }

                if ($this->shouldQueueIndexSynchronization()) {
                    $this->queueIndexSynchronization($chunk);
                }
            }
        });

        if ($flushCache) {
            $this->listingCache->flush();
        }
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
        if ($spotIds === []) {
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

    private function shouldQueueIndexSynchronization(): bool
    {
        return config('search.driver') === 'manticore';
    }
}
