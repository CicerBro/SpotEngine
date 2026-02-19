<?php

declare(strict_types=1);

use App\Models\Category;

test('returns abbreviated label for known slugs', function (string $slug, string $expected) {
    $category = new Category(['slug' => $slug, 'name' => 'Fallback']);

    expect($category->displayLabel())->toBe($expected);
})->with([
    ['divx', 'DivX'],
    ['bluray', 'Blu-ray'],
    ['mp3', 'MP3'],
    ['flac', 'FLAC'],
    ['windows', 'Win'],
    ['playstation4', 'PS4'],
    ['xbox360', 'X360'],
    ['ios', 'iOS'],
    ['epub', 'ePub'],
]);

test('falls back to name for unknown slugs', function () {
    $category = new Category(['slug' => 'some-unknown-slug', 'name' => 'My Category']);

    expect($category->displayLabel())->toBe('My Category');
});

test('falls back to slug when name is null', function () {
    $category = new Category(['slug' => 'custom-slug', 'name' => null]);

    expect($category->displayLabel())->toBe('custom-slug');
});
