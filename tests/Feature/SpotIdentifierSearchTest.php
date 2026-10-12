<?php

declare(strict_types=1);

use App\Data\SpotSearchCriteria;
use App\Models\Spot;
use App\Services\Search\Drivers\DatabaseSearchDriver;
use App\Services\SpotMutationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('identifier search matches exact values from indexed metadata and combines groups', function () {
    $match = Spot::factory()->create(['website' => 'https://imdb.com/title/tt1234567/', 'description' => 'tvdbid:42']);
    Spot::factory()->create(['website' => 'https://imdb.com/title/tt12345678/']);
    Spot::factory()->create(['description' => 'xtt1234567 tt1234567suffix']);
    Spot::factory()->create(['website' => 'https://imdb.com/title/tt1234567/', 'description' => 'tvdbid:420']);

    $result = (new DatabaseSearchDriver)->paginate(new SpotSearchCriteria(metadataTermGroups: [['tt1234567'], ['tvdbid:42']]));

    expect($result->getCollection()->modelKeys())->toBe([$match->id]);
    expect((new DatabaseSearchDriver)->count(new SpotSearchCriteria(metadataTermGroups: [['__invalid_imdb_identifier__']])))
        ->toBe(0);
});

test('bulk inserts upserts and lazy metadata updates replace normalized identifiers', function () {
    config(['search.driver' => 'manticore']);
    $mutations = app(SpotMutationService::class);
    $row = Spot::factory()->make(['message_id' => 'identifiers@example.test', 'website' => 'https://imdb.com/title/tt1234567/'])->getAttributes();
    unset($row['external_identifiers']);

    $mutations->insertOrIgnore([$row]);
    $spot = Spot::query()->where('message_id', 'identifiers@example.test')->firstOrFail();
    expect($spot->external_identifiers)->toBe(['imdb:1234567']);

    $mutations->upsert([['message_id' => $spot->message_id, ...$row, 'website' => 'https://imdb.com/title/tt12345678/']], ['message_id'], ['website']);
    expect($spot->refresh()->external_identifiers)->toBe(['imdb:12345678']);

    $mutations->update($spot, ['website' => null, 'description' => 'tvmaze:123']);
    expect($spot->refresh()->external_identifiers)->toBe(['tvmaze:123']);
    $this->assertDatabaseHas('spot_search_sync_queue', ['spot_id' => $spot->id]);
});

test('backfill processes bounded batches and restarts without revisiting completed rows', function () {
    config(['search.driver' => 'manticore']);
    $first = Spot::factory()->create(['website' => 'https://imdb.com/title/tt1234567/']);
    $second = Spot::factory()->create(['description' => 'tvdbid:42']);
    DB::table('spots')->whereIn('id', [$first->id, $second->id])->update(['external_identifiers' => null]);

    $this->artisan('spot:identifiers-backfill', ['--batch' => 1, '--limit' => 1])->assertSuccessful();
    expect($first->refresh()->external_identifiers)->toBe(['imdb:1234567']);
    expect($second->refresh()->external_identifiers)->toBeNull();

    $this->artisan('spot:identifiers-backfill', ['--batch' => 1])->assertSuccessful();
    expect($second->refresh()->external_identifiers)->toBe(['tvdb:42']);
    $this->assertDatabaseCount('spot_search_sync_queue', 2);
    $this->artisan('spot:identifiers-backfill', ['--batch' => 0])->assertFailed();
});

test('identifier normalization uses one read and one update per bounded chunk', function () {
    config(['search.driver' => 'database']);
    $spots = Spot::factory()->count(501)->create(['description' => 'imdb:1234567']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    app(SpotMutationService::class)->refreshIdentifiers($spots->modelKeys());

    expect(array_values(array_filter($queries, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE spots'))))->toHaveCount(2);
    expect(array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'for update'))))->toHaveCount(2);
    expect(array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'spot_search_sync_queue'))))->toBe([]);
});

test('lazy enrichment normalizes current persisted metadata when the caller holds an older snapshot', function () {
    $spot = Spot::factory()->create(['website' => 'https://imdb.com/title/tt1234567/', 'description' => null]);
    Spot::query()->whereKey($spot->id)->update(['website' => 'https://imdb.com/title/tt12345678/']);

    app(SpotMutationService::class)->update($spot, ['description' => 'tvdbid:42']);

    expect($spot->refresh()->external_identifiers)->toBe(['imdb:12345678', 'tvdb:42']);
});
