<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('profile page requires authentication', function () {
    $response = $this->get(route('profile'));

    $response->assertRedirect();
});

test('authenticated user can view profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('profile'));

    $response->assertSuccessful();
    $response->assertViewHas('user', $user);
});

test('user can regenerate API key', function () {
    $user = User::factory()->create();
    $oldKey = $user->api_token;

    $response = $this->actingAs($user)->post(route('profile.api-key.regenerate'));

    $response->assertRedirect(route('profile'));
    $response->assertSessionHas('success');
    $user->refresh();
    expect($user->api_token)->not->toBe($oldKey);
});
