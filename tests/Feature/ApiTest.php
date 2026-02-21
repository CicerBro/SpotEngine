<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('API caps returns XML without API key', function () {
    $response = $this->get(route('api', ['t' => 'caps']));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    $response->assertSee('SpotEngine');
    $response->assertSee('searching');
});

test('API search requires API key', function () {
    $response = $this->get(route('api', ['t' => 'search']));

    $response->assertStatus(401);
    $response->assertSee('API key is required');
});

test('API search with incorrect API key returns error', function () {
    $response = $this->get(route('api', ['t' => 'search', 'apikey' => 'wrongkey']));

    $response->assertStatus(401);
    $response->assertSee('Incorrect API key');
});

test('API search with valid key returns RSS', function () {
    $user = User::factory()->create();
    Spot::factory()->count(2)->create();

    $response = $this->get(route('api', [
        't' => 'search',
        'apikey' => $user->api_token,
        'q' => 'test',
    ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
});

test('API search reports total and offset for pagination', function () {
    $user = User::factory()->create();
    Spot::factory()->count(5)->create(['title' => 'unique pagination test']);

    $response = $this->get(route('api', [
        't' => 'search',
        'apikey' => $user->api_token,
        'q' => 'unique pagination test',
        'limit' => 2,
        'offset' => 2,
    ]));

    $response->assertSuccessful();
    $response->assertSee('offset="2"', false);
    $response->assertSee('total="5"', false);
});

test('API details requires API key', function () {
    $spot = Spot::factory()->create();

    $response = $this->get(route('api', [
        't' => 'details',
        'id' => $spot->id,
    ]));

    $response->assertStatus(401);
});

test('API details with valid key returns spot in RSS', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create();

    $response = $this->get(route('api', [
        't' => 'details',
        'id' => $spot->id,
        'apikey' => $user->api_token,
    ]));

    $response->assertSuccessful();
    $response->assertSee($spot->title);
});

test('API unknown action returns 202 error', function () {
    $response = $this->get(route('api', ['t' => 'unknown']));

    $response->assertStatus(400);
    $response->assertSee('No such function');
});
