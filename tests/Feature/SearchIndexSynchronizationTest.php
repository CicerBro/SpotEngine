<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\ListingCacheService;
use App\Services\Search\Contracts\SearchDriver;
use App\Services\Search\SearchIndexSynchronizer;
use App\Services\SpotMutationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('search.driver', 'manticore');
});

test('central spot mutations atomically queue index synchronization and flush listings', function () {
    config()->set('spotengine.listing_cache.enabled', true);
    $listingCache = app(ListingCacheService::class);
    $mutations = app(SpotMutationService::class);
    $request = Request::create('/', 'GET');
    $calls = 0;

    $listingCache->remember($request, function () use (&$calls): string {
        $calls++;

        return 'cached listing';
    });
    $listingCache->remember($request, function () use (&$calls): string {
        $calls++;

        return 'cached listing';
    });

    $spot = Spot::factory()->create();
    $mutations->update($spot, ['title' => 'Updated title']);
    $listingCache->remember($request, function () use (&$calls): string {
        $calls++;

        return 'refreshed listing';
    });

    expect($calls)->toBe(2)
        ->and(DB::table('spot_search_sync_queue')->where('spot_id', $spot->id)->exists())->toBeTrue();
});

test('manticore upserts queue index synchronization for rows without ids', function () {
    $mutations = app(SpotMutationService::class);

    $mutations->upsert([[
        'message_id' => 'queued-upsert@example.test',
        'title' => 'Queued Upsert',
        'category_code' => '01',
        'spot_posted_at' => now(),
    ]], ['message_id'], ['title']);

    $spot = Spot::query()->where('message_id', 'queued-upsert@example.test')->firstOrFail();

    expect(DB::table('spot_search_sync_queue')->where('spot_id', $spot->id)->exists())->toBeTrue();
});

test('database search upserts do not resolve spot ids for external synchronization', function () {
    config()->set('search.driver', 'database');
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'where "message_id" in')) {
            $queries[] = $query->sql;
        }
    });

    app(SpotMutationService::class)->upsert([[
        'message_id' => 'database-upsert@example.test',
        'title' => 'Database Upsert',
        'category_code' => '01',
        'spot_posted_at' => now(),
    ]], ['message_id'], ['title']);

    expect($queries)->toBeEmpty()
        ->and(DB::table('spot_search_sync_queue')->count())->toBe(0);
});

test('synchronizer indexes current rows deletes removed rows and acknowledges durable tokens', function () {
    $mutations = app(SpotMutationService::class);
    $existingSpot = Spot::factory()->create();
    $deletedSpot = Spot::factory()->create();

    $mutations->update($existingSpot, ['title' => 'Current']);
    $mutations->delete([$deletedSpot->id]);

    $driver = Mockery::mock(SearchDriver::class);
    $driver->shouldReceive('usesExternalIndex')->andReturnTrue();
    $driver->shouldReceive('ensureIndex')->once();
    $driver->shouldReceive('indexSpots')->once()->withArgs(
        fn (iterable $spots): bool => collect($spots)->pluck('id')->all() === [$existingSpot->id],
    );
    $driver->shouldReceive('deleteSpots')->once()->with([$deletedSpot->id]);

    $processed = (new SearchIndexSynchronizer($driver))->synchronizePending();

    expect($processed)->toBe(2)
        ->and(DB::table('spot_search_sync_queue')->count())->toBe(0);
});

test('failed external synchronization preserves the durable queue for retry', function () {
    $spot = Spot::factory()->create();
    app(SpotMutationService::class)->update($spot, ['title' => 'Retry me']);

    $driver = Mockery::mock(SearchDriver::class);
    $driver->shouldReceive('usesExternalIndex')->andReturnTrue();
    $driver->shouldReceive('ensureIndex')->once();
    $driver->shouldReceive('indexSpots')->once()->andThrow(new RuntimeException('service unavailable'));

    expect(fn () => (new SearchIndexSynchronizer($driver))->synchronizePending())
        ->toThrow(RuntimeException::class, 'service unavailable');

    expect(DB::table('spot_search_sync_queue')->where('spot_id', $spot->id)->exists())->toBeTrue();
});
