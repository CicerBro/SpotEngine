<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user treats spots posted after spots_read_until as unread', function () {
    $user = User::factory()->create([
        'spots_read_until' => now()->subHour(),
    ]);

    $readSpot = Spot::factory()->create([
        'title' => 'Already read spot',
        'spot_posted_at' => now()->subHours(2),
    ]);
    $unreadSpot = Spot::factory()->create([
        'title' => 'Unread spot title',
        'spot_posted_at' => now()->subMinutes(10),
    ]);

    expect($user->isSpotUnread($readSpot))->toBeFalse()
        ->and($user->isSpotUnread($unreadSpot))->toBeTrue()
        ->and($user->unreadSpotCount())->toBe(1);
});

test('user with no spots_read_until treats every spot as unread', function () {
    $user = User::factory()->create([
        'spots_read_until' => null,
    ]);

    Spot::factory()->count(2)->create();

    expect($user->unreadSpotCount())->toBe(2);
});

test('mark all spots read advances the user watermark', function () {
    $user = User::factory()->create([
        'spots_read_until' => now()->subDay(),
    ]);

    Spot::factory()->create([
        'spot_posted_at' => now()->subHour(),
    ]);

    expect($user->unreadSpotCount())->toBe(1);

    $user->markAllSpotsRead();

    expect($user->fresh()->unreadSpotCount())->toBe(0);
});

test('home page bolds unread spot titles', function () {
    $user = User::factory()->create([
        'spots_read_until' => now()->subHour(),
    ]);

    Spot::factory()->create([
        'title' => 'Bold unread title',
        'spot_posted_at' => now()->subMinutes(5),
    ]);
    Spot::factory()->create([
        'title' => 'Normal read title',
        'spot_posted_at' => now()->subHours(3),
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertSuccessful();
    $response->assertSee('Bold unread title');
    $response->assertSee('font-bold', false);
    $response->assertSee('Normal read title');
});

test('new filter only lists unread spots', function () {
    $user = User::factory()->create([
        'spots_read_until' => now()->subHour(),
    ]);

    $unread = Spot::factory()->create([
        'title' => 'Only unread listing',
        'spot_posted_at' => now()->subMinutes(5),
    ]);
    Spot::factory()->create([
        'title' => 'Hidden read listing',
        'spot_posted_at' => now()->subHours(3),
    ]);

    $response = $this->actingAs($user)->get('/?new=1');

    $response->assertSuccessful();
    $response->assertSee('Only unread listing');
    $response->assertDontSee('Hidden read listing');
    expect($response->viewData('spots')->pluck('id'))->toContain($unread->id);
});

test('mark all spots read clears unread listings', function () {
    $user = User::factory()->create([
        'spots_read_until' => now()->subHour(),
    ]);

    Spot::factory()->create([
        'spot_posted_at' => now()->subMinutes(5),
    ]);

    expect($user->unreadSpotCount())->toBe(1);

    $user->markAllSpotsRead();

    expect($user->fresh()->unreadSpotCount())->toBe(0);
});

test('mark all read advances watermark to latest spot posted time', function () {
    $user = User::factory()->create([
        'spots_read_until' => now()->subDay(),
    ]);

    $latestSpot = Spot::factory()->create([
        'spot_posted_at' => now()->subMinutes(10),
    ]);

    $user->markAllSpotsRead();

    expect($user->fresh()->spots_read_until?->equalTo($latestSpot->spot_posted_at))->toBeTrue();
});

test('mark all read endpoint clears bold title styling', function () {
    $user = User::factory()->create([
        'spots_read_until' => now()->subHour(),
    ]);

    Spot::factory()->create([
        'title' => 'Unread title after mark read',
        'spot_posted_at' => now()->subMinutes(5),
    ]);

    $before = $this->actingAs($user)->get('/');
    $before->assertSuccessful();
    expect($before->getContent())->toMatch('/font-bold[^>]*>\s*Unread title after mark read/s');

    $token = session()->token();

    $this->actingAs($user)
        ->from(route('home'))
        ->post(route('spots.mark-read'), ['_token' => $token])
        ->assertRedirect(route('home'));

    $after = $this->actingAs($user->fresh())->get('/');
    $after->assertSuccessful();
    expect($after->getContent())->not->toMatch('/font-bold[^>]*>\s*Unread title after mark read/s');
    expect($after->getContent())->toMatch('/font-normal[^>]*>\s*Unread title after mark read/s');
});
