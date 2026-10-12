<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use App\Services\ListingCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

test('cached pagination uses each request host and query without changing earlier responses', function (string $store) {
    config(['spotengine.listing_cache.enabled' => true, 'cache.default' => $store]);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    Spot::factory()->count(12)->create();

    $first = $this->actingAs($firstUser)->get('https://first.example.test/?per_page=10&api_token=first-secret');
    $second = $this->actingAs($secondUser)->get('https://second.example.test/?per_page=10&tracking=second');

    expect($second->viewData('spots')->nextPageUrl())
        ->toStartWith('https://second.example.test?')
        ->toContain('tracking=second')
        ->not->toContain('first-secret');
    expect($first->viewData('spots')->nextPageUrl())
        ->toStartWith('https://first.example.test?')
        ->toContain('api_token=first-secret')
        ->not->toContain('tracking=second');
})->with(['array', 'database']);

test('cached JSON pagination uses only the current request query and host', function () {
    config(['spotengine.listing_cache.enabled' => true]);
    $user = User::factory()->create();
    Spot::factory()->count(12)->create();

    $this->actingAs($user)->getJson('https://first.example.test/?per_page=10&api_token=private');
    $response = $this->actingAs($user)->getJson('https://second.example.test/?per_page=10&tracking=current');

    expect($response->json('next_url'))
        ->toStartWith('https://second.example.test?')
        ->toContain('tracking=current')
        ->not->toContain('private');
});

test('generation invalidation works on non database stores and preserves unrelated entries', function () {
    config(['spotengine.listing_cache.enabled' => true, 'cache.default' => 'array']);
    $service = app(ListingCacheService::class);
    $request = Request::create('/');
    Cache::put('unrelated-entry', 'keep', 60);
    $service->remember($request, fn (): string => 'stale');

    $service->flush();

    expect($service->remember($request, fn (): string => 'fresh'))->toBe('fresh');
    expect(Cache::get('unrelated-entry'))->toBe('keep');
});

test('invalidating during cache fill prevents in flight results from becoming current', function () {
    config(['spotengine.listing_cache.enabled' => true]);
    $service = app(ListingCacheService::class);
    $request = Request::create('/');

    $service->remember($request, function () use ($service): string {
        $service->flush();

        return 'stale';
    });

    expect($service->remember($request, fn (): string => 'fresh'))->toBe('fresh');
});

test('evicting a generation cannot reactivate previous listing entries', function () {
    config(['spotengine.listing_cache.enabled' => true]);
    $service = app(ListingCacheService::class);
    $request = Request::create('/');
    $service->remember($request, fn (): string => 'stale');
    Cache::forget('spots:listing:generation');

    expect($service->remember($request, fn (): string => 'fresh'))->toBe('fresh');
});
