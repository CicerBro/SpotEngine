<?php

declare(strict_types=1);

use App\Data\SpotSearchCriteria;
use App\Models\Spot;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\ManticoreDocumentMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('live manticore schema indexing filtering pagination and deletion match postgres', function () {
    if (env('MANTICORE_INTEGRATION') !== true) {
        $this->markTestSkipped('Set MANTICORE_INTEGRATION=true with Manticore on port 9308.');
    }

    $older = Spot::factory()->create([
        'title' => 'Manticore parity alpha',
        'category_code' => '01',
        'subcategories' => ['01a00', '01z01'],
        'website' => 'https://www.tvmaze.com/shows/12345/example',
        'spot_posted_at' => now()->subHour(),
    ]);
    $newer = Spot::factory()->create([
        'title' => 'Manticore parity beta',
        'category_code' => '01',
        'subcategories' => ['01a00', '01z01'],
        'website' => 'https://www.tvmaze.com/shows/12345/example',
        'spot_posted_at' => now(),
    ]);
    $otherCategory = Spot::factory()->create([
        'title' => 'Manticore parity excluded',
        'category_code' => '02',
        'subcategories' => ['02a00'],
        'spot_posted_at' => now()->addHour(),
    ]);
    $driver = new ManticoreSearchDriver(
        host: '127.0.0.1',
        port: 9308,
        index: 'spots_integration_test',
        documentMapper: new ManticoreDocumentMapper,
    );

    $driver->ensureIndex();
    $driver->truncateIndex();

    try {
        $driver->indexSpots(collect([$older, $newer, $otherCategory]));
        $firstPage = $driver->paginate(new SpotSearchCriteria(
            term: 'Manticore parity',
            category: '01',
            subcategories: ['01a00'],
            perPage: 1,
        ));
        $secondPage = $driver->paginate(new SpotSearchCriteria(
            term: 'Manticore parity',
            category: '01',
            subcategories: ['01a00'],
            perPage: 1,
            page: 2,
        ));
        $newznabOffset = $driver->paginate(new SpotSearchCriteria(
            category: '01',
            subcategories: ['01z01'],
            perPage: 1,
            termVariants: ['Manticore parity alpha', 'Manticore parity beta'],
            metadataTermGroups: [['tvmaze.com/shows/12345']],
            offset: 1,
        ));

        expect(array_map(static fn (Spot $spot): int => $spot->id, $firstPage->items()))
            ->toBe([$newer->id])
            ->and($firstPage->total())->toBe(2)
            ->and(array_map(static fn (Spot $spot): int => $spot->id, $secondPage->items()))
            ->toBe([$older->id])
            ->and($secondPage->total())->toBe(2)
            ->and(array_map(static fn (Spot $spot): int => $spot->id, $newznabOffset->items()))
            ->toBe([$older->id])
            ->and($newznabOffset->total())->toBe(2)
            ->and($driver->indexedDocumentCount())->toBe(3)
            ->and($driver->findIndexedIds([$older->id, $newer->id, $otherCategory->id]))
            ->toBe([$older->id, $newer->id, $otherCategory->id]);

        $driver->deleteSpot($older->id);

        expect($driver->indexedDocumentCount())->toBe(2);
    } finally {
        $driver->truncateIndex();
    }
});
