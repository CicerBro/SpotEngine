<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('generateApiKey returns 32 character hex string', function () {
    $key = User::generateApiKey();

    expect($key)->toHaveLength(32);
    expect($key)->toMatch('/^[a-f0-9]+$/');
});

test('regenerateApiKey updates user api_token', function () {
    $user = User::factory()->create();
    $original = $user->api_token;

    $newKey = $user->regenerateApiKey();

    expect($newKey)->toBe($user->fresh()->api_token);
    expect($newKey)->not->toBe($original);
});

test('factory-created user has valid api_token', function () {
    $user = User::factory()->create();

    expect($user->api_token)->not->toBeEmpty();
    expect($user->api_token)->toMatch('/^[a-f0-9]{32}$/');
});
