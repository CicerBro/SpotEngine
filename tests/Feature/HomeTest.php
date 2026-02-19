<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Spot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('home page returns successful response', function () {
    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertViewIs('spots.index');
});

test('home page shows spots with pagination', function () {
    Spot::factory()->count(3)->create();

    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertViewHas('spots');
    expect($response->viewData('spots')->total())->toBe(3);
});

test('home page renders spots as table rows without preview images', function () {
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

    $response = $this->get('/');

    $response->assertSuccessful();
    $response->assertSee('<table', false);
    $response->assertSee('Cat.');
    $response->assertSee('Sender');
    $response->assertSee('Image');
    $response->assertSee('Table View Spot');
    $response->assertDontSee(route('spots.image', $spot));
});

test('home page can filter by category', function () {
    Spot::factory()->inCategory('01')->count(2)->create();
    Spot::factory()->inCategory('02')->count(1)->create();

    $response = $this->get('/?cat=01');

    $response->assertSuccessful();
    expect($response->viewData('spots')->total())->toBe(2);
});

test('home page can filter by subcategory', function () {
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01a00']]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01a01']]);
    Spot::factory()->inCategory('02')->create(['subcategories' => ['02a00']]);

    $response = $this->get('/?cat=01&subcat[]=01a00');

    $response->assertSuccessful();
    expect($response->viewData('spots')->total())->toBe(1);
});

test('home page renders subcategory filters for active category', function () {
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

    $response = $this->get('/?cat=01&subcat[]=01a00&q=test');

    $response->assertSuccessful();
    $response->assertSee('Subcategories');
    $response->assertSee('name="subcat[]"', false);
    $response->assertSee('onchange="this.form.submit()"', false);
    $response->assertSeeInOrder(['value="01a00"', 'checked'], false);
    $response->assertSeeInOrder(['type', 'format']);
    $response->assertSee('Clear subcats');
    $response->assertSee('?cat=01');
    $response->assertDontSee('Apply');
    $response->assertDontSee('value="01c05"', false);
});

test('spot show page returns successful response for existing spot', function () {
    $spot = Spot::factory()->create();

    $response = $this->get(route('spots.show', $spot));

    $response->assertSuccessful();
    $response->assertViewIs('spots.show');
    $response->assertViewHas('spot', $spot);
});

test('spot show page renders bbcode description as safe html', function () {
    $spot = Spot::factory()->create([
        'description' => '[b]Bold title[/b] and [i]italic[/i] with [url=https://example.com]a link[/url].',
    ]);

    $response = $this->get(route('spots.show', $spot));

    $response->assertSuccessful();
    $response->assertSee('<strong>Bold title</strong>', false);
    $response->assertSee('<em>italic</em>', false);
    $response->assertSee('href="https://example.com"', false);
    $response->assertDontSee('<script>', false);
});

test('spot show returns 404 for non-existent spot', function () {
    $response = $this->get(route('spots.show', ['spot' => 99999]));

    $response->assertNotFound();
});

test('spot image returns placeholder when spot has no image', function () {
    $spot = Spot::factory()->create(['image_segment' => null]);

    $response = $this->get(route('spots.image', $spot));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'image/svg+xml');
    $response->assertSee('No Image');
});

test('categories JSON returns successful response', function () {
    $response = $this->get(route('categories.json'));

    $response->assertSuccessful();
    $response->assertJsonStructure([]);
});

test('spot NZB download requires authentication', function () {
    $spot = Spot::factory()->create();

    $response = $this->get(route('spots.nzb', $spot));

    $response->assertRedirect();
});

test('spot NZB returns 404 when spot has no nzb data', function () {
    $user = \App\Models\User::factory()->create();
    $spot = Spot::factory()->create(['nzb_segments' => []]);

    $response = $this->actingAs($user)->get(route('spots.nzb', $spot));

    $response->assertNotFound();
});
