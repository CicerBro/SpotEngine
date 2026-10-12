<?php

declare(strict_types=1);

use App\Data\SpotSearchCriteria;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\ManticoreDocumentMapper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('manticore counts identifiers using exact provider attributes without querying PostgreSQL', function () {
    Http::preventStrayRequests();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    Http::fake(['manticore.test:9308/search' => Http::response([
        'hits' => ['total' => 2, 'total_relation' => 'eq', 'hits' => []],
    ])]);
    $driver = new ManticoreSearchDriver('manticore.test', 9308, 'spots', new ManticoreDocumentMapper);

    expect($driver->count(new SpotSearchCriteria(metadataTermGroups: [['tt1234567', 'imdb:1234567'], ['tmdb:tv:45', 'tmdbid:45']])))
        ->toBe(2);

    expect($queries)->toBe([]);
    Http::assertSent(function (Request $request): bool {
        expect($request->data()['query'])->toBe(['bool' => ['must' => [
            ['bool' => ['should' => [['in' => ['any(imdb_ids)' => [1234567]]]], 'minimum_should_match' => 1]],
            ['bool' => ['should' => [
                ['in' => ['any(tmdb_tv_ids)' => [45]]],
                ['in' => ['any(tmdb_ids)' => [45]]],
            ], 'minimum_should_match' => 1]],
        ]]]);
        expect($request->data()['options']['cutoff'])->toBe(0);

        return true;
    });
});

test('invalid identifier groups cannot expand into unrestricted manticore results', function () {
    Http::preventStrayRequests();
    Http::fake(['manticore.test:9308/search' => Http::response([
        'hits' => ['total' => 0, 'total_relation' => 'eq', 'hits' => []],
    ])]);
    $driver = new ManticoreSearchDriver('manticore.test', 9308, 'spots', new ManticoreDocumentMapper);

    expect($driver->count(new SpotSearchCriteria(metadataTermGroups: [['__invalid_imdb_identifier__']])))
        ->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->data()['query'] === ['bool' => ['must' => [['equals' => ['id' => 0]]]]]);
});
