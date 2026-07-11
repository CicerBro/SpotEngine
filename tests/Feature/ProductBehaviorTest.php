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

test('unfinished product controls are not exposed', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['title' => 'Supported listing']);

    $response = $this->actingAs($user)->get('/?exc_subcat[]=unsupported-code');

    $response->assertSuccessful();
    $response->assertSee($spot->title)
        ->assertDontSee('unsupported-code')
        ->assertDontSee('Add to watchlist');
});
