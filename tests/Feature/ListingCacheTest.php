<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use App\Services\ListingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('listing cache stores and returns cached spots when enabled', function () {
    config(['spotengine.listing_cache.enabled' => true, 'spotengine.listing_cache.ttl' => 5]);

    $user = User::factory()->create();
    Spot::factory()->count(3)->create();

    $response1 = $this->actingAs($user)->get('/');
    $response1->assertSuccessful();
    expect($response1->viewData('spotCount'))->toBe(3);

    // Create more spots — cached response should still show 3
    Spot::factory()->count(2)->create();

    $response2 = $this->actingAs($user)->get('/');
    $response2->assertSuccessful();
    expect($response2->viewData('spotCount'))->toBe(3);
});

test('listing cache is bypassed when disabled', function () {
    config(['spotengine.listing_cache.enabled' => false]);

    $user = User::factory()->create();
    Spot::factory()->count(3)->create();

    $response1 = $this->actingAs($user)->get('/');
    expect($response1->viewData('spotCount'))->toBe(3);

    Spot::factory()->count(2)->create();

    $response2 = $this->actingAs($user)->get('/');
    expect($response2->viewData('spotCount'))->toBe(5);
});

test('different query params produce separate cache entries', function () {
    config(['spotengine.listing_cache.enabled' => true, 'spotengine.listing_cache.ttl' => 5]);

    $user = User::factory()->create();
    Spot::factory()->count(3)->create();

    $response1 = $this->actingAs($user)->get('/?per_page=25');
    $response1->assertSuccessful();

    $response2 = $this->actingAs($user)->get('/?per_page=50');
    $response2->assertSuccessful();

    // Both should return 3 spots (same data, different cache keys)
    expect($response1->viewData('spotCount'))->toBe(3);
    expect($response2->viewData('spotCount'))->toBe(3);

    // Add spots — per_page=25 cached result stays at 3
    Spot::factory()->count(2)->create();

    $response3 = $this->actingAs($user)->get('/?per_page=25');
    expect($response3->viewData('spotCount'))->toBe(3);
});

test('flush clears all listing cache entries', function () {
    config(['spotengine.listing_cache.enabled' => true, 'spotengine.listing_cache.ttl' => 5]);

    $user = User::factory()->create();
    Spot::factory()->count(3)->create();

    // Warm cache
    $this->actingAs($user)->get('/');
    $this->actingAs($user)->get('/?per_page=25');

    // Add more spots
    Spot::factory()->count(2)->create();

    // Flush cache
    app(ListingCacheService::class)->flush();

    // Should now see 5 spots
    $response = $this->actingAs($user)->get('/');
    expect($response->viewData('spotCount'))->toBe(5);

    $response2 = $this->actingAs($user)->get('/?per_page=25');
    expect($response2->viewData('spotCount'))->toBe(5);
});

test('listing cache separates cursor JSON fragments from full page responses', function () {
    config(['spotengine.listing_cache.enabled' => true, 'spotengine.listing_cache.ttl' => 5]);

    $user = User::factory()->create();
    Spot::factory()->count(12)->create();

    $firstPage = $this->actingAs($user)->get('/?per_page=10');
    $nextPageUrl = $firstPage->viewData('spots')->nextPageUrl();

    expect($nextPageUrl)->not->toBeNull();

    $this->actingAs($user)
        ->getJson($nextPageUrl)
        ->assertSuccessful()
        ->assertJsonPath('count', 2);

    $fullPage = $this->actingAs($user)->get($nextPageUrl);

    $fullPage->assertSuccessful();
    expect($fullPage->viewData('spotCount'))->toBe(12);
});
