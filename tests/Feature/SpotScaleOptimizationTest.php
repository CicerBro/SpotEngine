<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\NzbDownloadService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('listing payload exposes has nzb without hydrating segment arrays', function () {
    $user = User::factory()->create();
    Spot::factory()->create(['nzb_segments' => ['large-segment-list@example.test']]);

    $response = $this->actingAs($user)->get('/');

    $response->assertSuccessful();
    $spot = $response->viewData('spots')->first();

    expect($spot->has_nzb)->toBeTrue()
        ->and($spot->getAttributes())->not->toHaveKey('nzb_segments');
});

test('unenriched backlog has a postgres partial index', function () {
    $definition = DB::table('pg_indexes')
        ->where('tablename', 'spots')
        ->where('indexname', 'idx_spots_unenriched')
        ->value('indexdef');

    expect($definition)->toContain('WHERE (xml_signature IS NULL)');
});

test('precache scans use an id keyset instead of offsets', function () {
    Spot::factory()->count(5)->create(['nzb_segments' => ['segment@example.test']]);
    config()->set('spotengine.cache.nzb_retention_days', 0);
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'from "spots"')) {
            $queries[] = $query->sql;
        }
    });

    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('connect')->once();
    $driver->shouldReceive('quit')->once();
    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('makeDriver')->once()->andReturn($driver);
    $nzbService = Mockery::mock(NzbDownloadService::class);
    $nzbService->shouldReceive('cachePath')->times(4)->andReturn(__FILE__);
    $this->app->instance(NntpService::class, $nntpService);
    $this->app->instance(NzbDownloadService::class, $nzbService);

    $this->artisan('spot:precache', [
        '--type' => 'nzb',
        '--batch' => 2,
        '--limit' => 4,
    ])->assertSuccessful();

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $query) {
        expect(mb_strtolower($query))->toContain('"id" < ?')
            ->not->toContain(' offset ');
    }
});
