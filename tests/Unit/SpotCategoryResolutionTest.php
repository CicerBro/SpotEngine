<?php

declare(strict_types=1);

use App\Enums\RootCategory;
use App\Models\Category;
use App\Models\Spot;

test('root_category returns correct enum for valid codes', function () {
    $spot = new Spot(['category_code' => '01']);

    expect($spot->root_category)->toBe(RootCategory::Image);
});

test('root_category returns null for unknown code', function () {
    $spot = new Spot(['category_code' => '99']);

    expect($spot->root_category)->toBeNull();
});

test('resolveBadgeCategory finds format subcategory for image spots', function () {
    $formatCategory = new Category(['code' => '01a01', 'name' => 'DivX', 'slug' => 'divx', 'type' => 'format']);
    $genreCategory = new Category(['code' => '01b01', 'name' => 'Action', 'slug' => 'action', 'type' => 'genre']);

    $categoriesByCode = collect([
        '01a01' => $formatCategory,
        '01b01' => $genreCategory,
    ]);

    $spot = new Spot([
        'category_code' => '01',
        'subcategories' => ['01b01', '01a01'],
    ]);

    expect($spot->resolveBadgeCategory($categoriesByCode))->toBe($formatCategory);
});

test('resolveBadgeCategory finds platform subcategory for game spots', function () {
    $platformCategory = new Category(['code' => '03c01', 'name' => 'Windows', 'slug' => 'windows', 'type' => 'platform']);
    $formatCategory = new Category(['code' => '03a01', 'name' => 'ISO', 'slug' => 'iso', 'type' => 'format']);

    $categoriesByCode = collect([
        '03c01' => $platformCategory,
        '03a01' => $formatCategory,
    ]);

    $spot = new Spot([
        'category_code' => '03',
        'subcategories' => ['03a01', '03c01'],
    ]);

    expect($spot->resolveBadgeCategory($categoriesByCode))->toBe($platformCategory);
});

test('resolveBadgeCategory skips categories named dash', function () {
    $dashCategory = new Category(['code' => '01a00', 'name' => '-', 'slug' => 'none', 'type' => 'format']);
    $validCategory = new Category(['code' => '01a01', 'name' => 'DivX', 'slug' => 'divx', 'type' => 'type']);

    $categoriesByCode = collect([
        '01a00' => $dashCategory,
        '01a01' => $validCategory,
    ]);

    $spot = new Spot([
        'category_code' => '01',
        'subcategories' => ['01a00', '01a01'],
    ]);

    expect($spot->resolveBadgeCategory($categoriesByCode))->toBe($validCategory);
});

test('resolveBadgeCategory returns null when no match found', function () {
    $spot = new Spot([
        'category_code' => '01',
        'subcategories' => ['unknown'],
    ]);

    expect($spot->resolveBadgeCategory(collect()))->toBeNull();
});

test('resolveGenreLabel returns genre name', function () {
    $genreCategory = new Category(['code' => '01b05', 'name' => 'Action', 'slug' => 'action', 'type' => 'genre']);

    $categoriesByCode = collect(['01b05' => $genreCategory]);

    $spot = new Spot([
        'category_code' => '01',
        'subcategories' => ['01b05'],
    ]);

    expect($spot->resolveGenreLabel($categoriesByCode))->toBe('Action');
});

test('resolveGenreLabel skips dash genre', function () {
    $dashGenre = new Category(['code' => '01b00', 'name' => '-', 'slug' => 'none', 'type' => 'genre']);
    $realGenre = new Category(['code' => '01b05', 'name' => 'Comedy', 'slug' => 'comedy', 'type' => 'genre']);

    $categoriesByCode = collect([
        '01b00' => $dashGenre,
        '01b05' => $realGenre,
    ]);

    $spot = new Spot([
        'category_code' => '01',
        'subcategories' => ['01b00', '01b05'],
    ]);

    expect($spot->resolveGenreLabel($categoriesByCode))->toBe('Comedy');
});

test('resolveGenreLabel returns null when no genre', function () {
    $spot = new Spot([
        'category_code' => '01',
        'subcategories' => [],
    ]);

    expect($spot->resolveGenreLabel(collect()))->toBeNull();
});
