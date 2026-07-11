<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Spot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('home page returns successful response', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertSuccessful();
    $response->assertViewIs('spots.index');
});

test('home page shows spots with cursor pagination', function () {
    $user = User::factory()->create();
    Spot::factory()->count(3)->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertSuccessful();
    $response->assertViewHas('spots');
    expect($response->viewData('spotCount'))->toBe(3)
        ->and($response->viewData('spots')->count())->toBe(3);
});

test('home page renders spots as table rows with lazy hover images', function () {
    $user = User::factory()->create();

    Category::create([
        'code' => '01',
        'parent_code' => null,
        'name' => 'Image',
        'slug' => 'image',
        'type' => null,
        'sort_order' => 1,
    ]);

    $spot = Spot::factory()->inCategory('01')->create([
        'title' => 'Table View Spot',
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertSuccessful();
    $response->assertSee('<table', false);
    $response->assertSee('Cat.');
    $response->assertSee('Sender');
    $response->assertSee('Image');
    $response->assertSee('Table View Spot');
    $response->assertSee('spotengine-mark.svg');
    $response->assertSee('infiniteSpots(', false);
    $response->assertSee('Loading more spots');
    // Image URL is in Alpine.js hover attribute, not an eager <img> tag
    $response->assertSee(route('spots.image', $spot));
    $response->assertDontSee('<img src="'.route('spots.image', $spot).'"', false);
});

test('home page orders equal timestamps deterministically for cursor pagination', function () {
    $user = User::factory()->create();
    $postedAt = now()->startOfSecond();

    $spots = Spot::factory()->count(12)->create([
        'spot_posted_at' => $postedAt,
    ]);

    $response = $this->actingAs($user)->get('/?per_page=10');

    $response->assertSuccessful();

    expect($response->viewData('spots')->pluck('id')->all())
        ->toBe($spots->pluck('id')->sortDesc()->take(10)->values()->all());
});

test('home page returns the next cursor batch as rendered rows', function () {
    $user = User::factory()->create();

    Spot::factory()
        ->count(12)
        ->sequence(fn ($sequence) => [
            'title' => 'Cursor Spot '.$sequence->index,
            'spot_posted_at' => now()->subMinutes($sequence->index),
        ])
        ->create();

    $response = $this->actingAs($user)->get('/?per_page=10');
    $nextPageUrl = $response->viewData('spots')->nextPageUrl();

    expect($nextPageUrl)->not->toBeNull();

    $cursorResponse = $this->actingAs($user)->getJson($nextPageUrl);

    $cursorResponse
        ->assertSuccessful()
        ->assertJsonStructure(['html', 'next_url', 'has_more', 'count'])
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('count', 2);

    expect($cursorResponse->json('html'))
        ->toContain('Cursor Spot 10')
        ->toContain('Cursor Spot 11');
});

test('home page can filter by category', function () {
    $user = User::factory()->create();
    Spot::factory()->inCategory('01')->count(2)->create();
    Spot::factory()->inCategory('02')->count(1)->create();

    $response = $this->actingAs($user)->get('/?cat=01');

    $response->assertSuccessful();
    expect($response->viewData('spotCount'))->toBe(2);
});

test('home page can filter by subcategory', function () {
    $user = User::factory()->create();
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01a00']]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01a01']]);
    Spot::factory()->inCategory('02')->create(['subcategories' => ['02a00']]);

    $response = $this->actingAs($user)->get('/?cat=01&subcat[]=01a00');

    $response->assertSuccessful();
    expect($response->viewData('spotCount'))->toBe(1);
});

test('home page renders subcategory filters for active category', function () {
    $user = User::factory()->create();
    Category::clearCache();

    Category::create([
        'code' => '01',
        'parent_code' => null,
        'name' => 'Image',
        'slug' => 'image',
        'type' => null,
        'sort_order' => 1,
    ]);

    Category::create([
        'code' => '01a00',
        'parent_code' => '01',
        'name' => 'DivX',
        'slug' => 'divx',
        'type' => 'format',
        'sort_order' => 1,
    ]);

    Category::create([
        'code' => '01z00',
        'parent_code' => '01',
        'name' => 'Movie',
        'slug' => 'movie',
        'type' => 'type',
        'sort_order' => 1,
    ]);

    Category::create([
        'code' => '01b00',
        'parent_code' => '01',
        'name' => 'CAM',
        'slug' => 'cam',
        'type' => 'source',
        'sort_order' => 1,
    ]);

    Category::create([
        'code' => '01c05',
        'parent_code' => '01',
        'name' => '-',
        'slug' => 'c05',
        'type' => 'language',
        'sort_order' => 1,
    ]);

    $response = $this->actingAs($user)->get('/?cat=01&subcat[]=01a00&q=test');

    $response->assertSuccessful();
    // Active filter pills show the active category and subcat
    $response->assertSee('Image');
    $response->assertSee('DivX');
    // Sidebar shows format/type subcats as links (not checkboxes)
    $response->assertSee('Movie'); // type subcat
    $response->assertSee('?cat=01');
    // Hidden subcats (name='-') are not shown
    $response->assertDontSee('value="01c05"', false);
});

test('spot show page returns successful response for existing spot', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create();

    $response = $this->actingAs($user)->get(route('spots.show', $spot));

    $response->assertSuccessful();
    $response->assertViewIs('spots.show');
    $response->assertViewHas('spot', $spot);
});

test('spot show page renders bbcode description as safe html', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create([
        'description' => '[b]Bold title[/b] and [i]italic[/i] with [url=https://example.com]a link[/url].<script>alert(1)</script>',
    ]);

    $response = $this->actingAs($user)->get(route('spots.show', $spot));

    $response->assertSuccessful();
    $response->assertSee('<strong>Bold title</strong>', false);
    $response->assertSee('<em>italic</em>', false);
    $response->assertSee('href="https://example.com"', false);
    $response->assertDontSee('<script>alert(1)</script>', false);
});

test('spot show returns 404 for non-existent spot', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('spots.show', ['spot' => 99999]));

    $response->assertNotFound();
});

test('spot image returns placeholder when spot has no image', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['image_segment' => null]);

    $response = $this->actingAs($user)->get(route('spots.image', $spot));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'image/svg+xml');
    $response->assertSee('No Image');
});

test('categories JSON returns successful response', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('categories.json'));

    $response->assertSuccessful();
    $response->assertJsonStructure([]);
});

test('spot NZB download requires authentication', function () {
    $spot = Spot::factory()->create();

    $response = $this->get(route('spots.nzb', $spot));

    $response->assertRedirect();
});

test('spot NZB returns 404 when spot has no nzb data', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['nzb_segments' => []]);

    $response = $this->actingAs($user)->get(route('spots.nzb', $spot));

    $response->assertNotFound();
});
