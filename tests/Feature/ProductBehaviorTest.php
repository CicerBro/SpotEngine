<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use App\Services\NzbDownloadService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('spot NZB downloads are recorded for the user', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['title' => 'Tracked web download']);
    $nzbService = Mockery::mock(NzbDownloadService::class);
    $nzbService->shouldReceive('fetchNzb')->once()->andReturn('<nzb/>');
    $nzbService->shouldReceive('filename')->once()->andReturn('tracked.nzb');
    $this->app->instance(NzbDownloadService::class, $nzbService);

    $response = $this->actingAs($user)->get(route('spots.nzb', $spot));

    $response->assertSuccessful();
    $this->assertDatabaseHas('user_downloads', [
        'user_id' => $user->id,
        'spot_id' => $spot->id,
    ]);
});

test('spot NZB downloads serve gzip when accepted', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['title' => 'Gzipped web download']);
    $plainNzb = '<nzb><file subject="web"/></nzb>';
    $gzippedNzb = gzencode($plainNzb, 8);

    if ($gzippedNzb === false) {
        throw new RuntimeException('Unable to create gzipped NZB test fixture.');
    }

    $nzbService = Mockery::mock(NzbDownloadService::class);
    $nzbService->shouldReceive('fetchGzippedNzb')->once()->andReturn($gzippedNzb);
    $nzbService->shouldNotReceive('fetchNzb');
    $nzbService->shouldReceive('filename')->once()->andReturn('tracked.nzb');
    $this->app->instance(NzbDownloadService::class, $nzbService);

    $response = $this->actingAs($user)->get(route('spots.nzb', $spot), [
        'Accept-Encoding' => 'gzip',
    ]);

    $response->assertSuccessful();
    $response->assertHeader('Content-Encoding', 'gzip');
    $response->assertHeader('Vary', 'Accept-Encoding');
    expect($response->getContent())->toBe($gzippedNzb)
        ->and(gzdecode((string) $response->getContent()))->toBe($plainNzb);
    $this->assertDatabaseHas('user_downloads', [
        'user_id' => $user->id,
        'spot_id' => $spot->id,
    ]);
});

test('unfinished product controls are not exposed', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['title' => 'Supported listing']);

    $response = $this->actingAs($user)->get('/?exc_subcat[]=unsupported-code');

    $response->assertSuccessful();
    $response->assertSee($spot->title)
        ->assertDontSee('unsupported-code')
        ->assertDontSee('Add to watchlist');
});
