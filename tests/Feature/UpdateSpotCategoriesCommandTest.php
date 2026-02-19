<?php

declare(strict_types=1);

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('command updates categories from Spotweb definitions', function () {
    $this->artisan('spot:categories:update')
        ->assertSuccessful()
        ->expectsOutputToContain('Categories updated. Cache cleared.');

    expect(Category::count())->toBeGreaterThan(0);
});

test('command creates head categories with correct codes and names', function () {
    $this->artisan('spot:categories:update')->assertSuccessful();

    $heads = Category::whereNull('parent_code')->orderBy('code')->get();

    expect($heads)->toHaveCount(4)
        ->and($heads->pluck('name', 'code')->toArray())->toBe([
            '01' => 'Image',
            '02' => 'Sound',
            '03' => 'Games',
            '04' => 'Applications',
        ]);
});

test('command creates subcategories with parent_code and type', function () {
    $this->artisan('spot:categories:update')->assertSuccessful();

    $movie = Category::where('code', '01z00')->first();
    expect($movie)->not->toBeNull()
        ->and($movie->parent_code)->toBe('01')
        ->and($movie->name)->toBe('Movie')
        ->and($movie->type)->toBe('type');

    $divx = Category::where('code', '01a00')->first();
    expect($divx)->not->toBeNull()
        ->and($divx->parent_code)->toBe('01')
        ->and($divx->name)->toBe('DivX')
        ->and($divx->type)->toBe('format');
});

test('command is idempotent and updates existing categories', function () {
    Category::create([
        'code' => '01',
        'parent_code' => null,
        'name' => 'Old Image',
        'slug' => 'old-image',
        'type' => null,
        'sort_order' => 1,
    ]);

    $this->artisan('spot:categories:update')->assertSuccessful();

    $cat = Category::where('code', '01')->first();
    expect($cat->name)->toBe('Image')
        ->and($cat->slug)->toBe('image');
});
