<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use App\Services\SpotImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('spot image returns a non-cacheable placeholder when no preview exists', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create([
        'image_segments' => [],
    ]);

    $response = $this->actingAs($user)->get(route('spots.image', $spot));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'image/svg+xml');
    $response->assertHeader('Cache-Control', 'no-store, private');
    $response->assertSee('No Image');
});

test('spot image responses use validated metadata and cache headers', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->withImage()->create();
    $imageData = (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );
    $imageService = Mockery::mock(SpotImageService::class);
    $imageService->shouldReceive('fetch')->once()->withArgs(
        static fn (Spot $requestedSpot): bool => $requestedSpot->is($spot),
    )->andReturn([
        'data' => $imageData,
        'content_type' => 'image/png',
        'from_cache' => true,
    ]);
    $this->app->instance(SpotImageService::class, $imageService);

    $response = $this->actingAs($user)->get(route('spots.image', $spot));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'image/png');
    $response->assertHeader('Content-Length', (string) strlen($imageData));
    $response->assertHeader('Cache-Control', 'immutable, max-age=2592000, public');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->getContent())->toBe($imageData);
});
