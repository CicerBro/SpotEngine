<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Search\Contracts\SearchDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('search.driver', 'manticore');
});

test('rebuild command is idempotent and verifies exact index parity', function () {
    $spots = Spot::factory()->count(3)->create();
    $ids = $spots->modelKeys();
    $driver = Mockery::mock(SearchDriver::class);
    $driver->shouldReceive('usesExternalIndex')->andReturnTrue();
    $driver->shouldReceive('ensureIndex')->times(3);
    $driver->shouldReceive('truncateIndex')->once();
    $driver->shouldReceive('indexSpots')->once()->withArgs(
        fn (iterable $indexedSpots): bool => collect($indexedSpots)->pluck('id')->all() === $ids,
    );
    $driver->shouldReceive('indexedDocumentCount')->once()->andReturn(3);
    $driver->shouldReceive('findIndexedIds')->once()->with($ids)->andReturn($ids);
    $this->app->instance(SearchDriver::class, $driver);

    $this->artisan('spot:search-rebuild', ['--batch' => 100])
        ->assertSuccessful()
        ->expectsOutputToContain('Indexed 3 spots')
        ->expectsOutputToContain('Search index parity verified');
});

test('reconciliation check fails when manticore has missing documents', function () {
    $spots = Spot::factory()->count(2)->create();
    $ids = $spots->modelKeys();
    $driver = Mockery::mock(SearchDriver::class);
    $driver->shouldReceive('usesExternalIndex')->andReturnTrue();
    $driver->shouldReceive('ensureIndex')->once();
    $driver->shouldReceive('indexedDocumentCount')->once()->andReturn(1);
    $driver->shouldReceive('findIndexedIds')->once()->with($ids)->andReturn([$ids[0]]);
    $this->app->instance(SearchDriver::class, $driver);

    $this->artisan('spot:search-rebuild', ['--check' => true, '--batch' => 100])
        ->assertFailed()
        ->expectsOutputToContain('Search index parity check failed');
});
