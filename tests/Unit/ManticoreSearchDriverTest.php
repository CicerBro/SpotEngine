<?php

declare(strict_types=1);

use App\Data\SpotSearchCriteria;
use App\Enums\SearchField;
use App\Exceptions\ManticoreSearchException;
use App\Models\Spot;
use App\Services\Search\Drivers\DatabaseSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\ManticoreDocumentMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function manticoreDriver(array $overrides = []): ManticoreSearchDriver
{
    return new ManticoreSearchDriver(
        host: $overrides['host'] ?? 'manticore.test',
        port: $overrides['port'] ?? 9308,
        index: $overrides['index'] ?? 'spots',
        documentMapper: new ManticoreDocumentMapper,
        timeout: 1,
        maxMatches: 10_000,
    );
}

test('manticore query engine owns text filters sorting and pagination', function () {
    $older = Spot::factory()->create([
        'title' => 'Ubuntu release',
        'category_code' => '01',
        'subcategories' => ['01a00'],
        'spot_posted_at' => now()->subDay(),
    ]);
    $newer = Spot::factory()->create([
        'title' => 'Ubuntu newer release',
        'category_code' => '01',
        'subcategories' => ['01a00'],
        'spot_posted_at' => now(),
    ]);

    Http::fake(function (Request $request) use ($older, $newer) {
        $payload = $request->data();

        expect($payload['query'])->toBe([
            'bool' => [
                'must' => [
                    ['match' => ['title,description' => 'Ubuntu']],
                    ['equals' => ['category' => 1]],
                    ['in' => ['subcategories' => [(new ManticoreDocumentMapper)->subcategory('01a00')]]],
                ],
            ],
        ])->and($payload['sort'])->toBe([
            ['posted_at' => 'desc'],
            ['id' => 'desc'],
        ])->and($payload['offset'])->toBe(0)
            ->and($payload['limit'])->toBe(2);

        return Http::response([
            'hits' => [
                'total' => 2,
                'total_relation' => 'eq',
                'hits' => [
                    ['_id' => $newer->id, '_source' => []],
                    ['_id' => $older->id, '_source' => []],
                ],
            ],
        ]);
    });

    $spots = manticoreDriver()->paginate(new SpotSearchCriteria(
        term: 'Ubuntu',
        field: SearchField::Both,
        category: '01',
        subcategories: ['01a00'],
        perPage: 2,
    ));

    expect(array_map(static fn (Spot $spot): int => $spot->id, $spots->items()))->toBe([$newer->id, $older->id])
        ->and($spots->total())->toBe(2)
        ->and($spots->first()->getAttributes())->toHaveKey('has_nzb')
        ->and($spots->first()->getAttributes())->not->toHaveKey('nzb_segments');
});

test('database and manticore listing engines preserve filter and ordering parity', function () {
    Spot::factory()->create([
        'category_code' => '02',
        'subcategories' => ['02a00'],
        'spot_posted_at' => now()->addHour(),
    ]);
    $older = Spot::factory()->create([
        'category_code' => '01',
        'subcategories' => ['01a00'],
        'spot_posted_at' => now()->subHour(),
    ]);
    $newer = Spot::factory()->create([
        'category_code' => '01',
        'subcategories' => ['01a00'],
        'spot_posted_at' => now(),
    ]);

    $criteria = new SpotSearchCriteria(
        category: '01',
        subcategories: ['01a00'],
        perPage: 10,
    );
    $databaseIds = array_map(
        static fn (Spot $spot): int => $spot->id,
        (new DatabaseSearchDriver)->paginate($criteria)->items(),
    );

    Http::fake([
        'manticore.test:9308/search' => Http::response([
            'hits' => [
                'total' => 2,
                'total_relation' => 'eq',
                'hits' => [['_id' => $newer->id], ['_id' => $older->id]],
            ],
        ]),
    ]);
    $manticoreIds = array_map(
        static fn (Spot $spot): int => $spot->id,
        manticoreDriver()->paginate($criteria)->items(),
    );

    expect($manticoreIds)->toBe($databaseIds);
});

test('manticore supports newznab variants identifiers subcategories and arbitrary offsets', function () {
    $result = Spot::factory()->create([
        'title' => 'Compatibility Show S01E02',
        'description' => 'Serialized API description',
        'website' => 'https://www.tvmaze.com/shows/12345/example',
        'category_code' => '01',
        'subcategories' => ['01z01'],
    ]);

    Http::fake(function (Request $request) use ($result) {
        $payload = $request->data();

        expect($payload['query'])->toBe([
            'bool' => [
                'must' => [
                    [
                        'bool' => [
                            'should' => [
                                ['match' => ['title' => 'Compatibility Show S01E02']],
                                ['match' => ['title' => 'Compatibility Show Season 1 Episode 2']],
                            ],
                        ],
                    ],
                    ['in' => ['id' => [$result->id]]],
                    ['equals' => ['category' => 1]],
                    ['in' => ['subcategories' => [(new ManticoreDocumentMapper)->subcategory('01z01')]]],
                ],
            ],
        ])->and($payload['offset'])->toBe(3)
            ->and($payload['limit'])->toBe(2);

        return Http::response([
            'hits' => [
                'total' => 7,
                'total_relation' => 'eq',
                'hits' => [['_id' => $result->id]],
            ],
        ]);
    });

    $spots = manticoreDriver()->paginate(new SpotSearchCriteria(
        category: '01',
        subcategories: ['01z01'],
        perPage: 2,
        termVariants: [
            'Compatibility Show S01E02',
            'Compatibility Show Season 1 Episode 2',
        ],
        metadataTermGroups: [[
            'tvmaze.com/shows/12345',
            'tvmaze:12345',
        ]],
        offset: 3,
    ));

    expect($spots->total())->toBe(7)
        ->and($spots->items())->toHaveCount(1)
        ->and($spots->first()->description)->toBe('Serialized API description');
});

test('manticore bulk replacement indexes all searchable attributes', function () {
    $spot = Spot::factory()->create([
        'title' => 'Indexed title',
        'description' => 'Indexed description',
        'website' => 'https://www.imdb.com/title/tt1234567/',
        'category_code' => '03',
        'subcategories' => ['03b12'],
        'file_size' => 123456,
        'nzb_segments' => ['segment@example.test'],
    ]);

    Http::fake(['manticore.test:9308/bulk' => Http::response("{}\n")]);

    manticoreDriver()->indexSpot($spot);

    Http::assertSent(function (Request $request) use ($spot): bool {
        $operation = json_decode(trim($request->body()), true, flags: JSON_THROW_ON_ERROR);
        $document = $operation['replace']['doc'];

        return $operation['replace']['id'] === $spot->id
            && $document['title'] === 'Indexed title'
            && $document['description'] === 'Indexed description'
            && $document['category'] === 3
            && $document['subcategories'] !== []
            && $document['posted_at'] === $spot->spot_posted_at->timestamp
            && $document['file_size'] === 123456
            && $document['has_nzb'] === true;
    });
});

test('manticore configuration and connectivity fail fast with clear errors', function () {
    expect(fn () => manticoreDriver(['index' => 'invalid-name']))
        ->toThrow(ManticoreSearchException::class, 'valid unqualified table name');

    Http::fake([
        'manticore.test:9308/search' => Http::failedConnection(),
    ]);

    expect(fn () => manticoreDriver()->paginate(new SpotSearchCriteria))
        ->toThrow(ManticoreSearchException::class, 'Manticore is unavailable');
});

test('manticore never silently applies a database builder search', function () {
    expect(fn () => manticoreDriver()->search(Spot::query(), 'ignored'))
        ->toThrow(ManticoreSearchException::class, 'must use the query-engine paginator');
});
