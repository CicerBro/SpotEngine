<?php

declare(strict_types=1);

use App\Enums\RootCategory;

test('can be created from valid root codes', function (string $code, RootCategory $expected) {
    expect(RootCategory::from($code))->toBe($expected);
})->with([
    ['01', RootCategory::Image],
    ['02', RootCategory::Audio],
    ['03', RootCategory::Games],
    ['04', RootCategory::Applications],
]);

test('tryFrom returns null for unknown codes', function () {
    expect(RootCategory::tryFrom('99'))->toBeNull();
});

test('returns CSS color variable', function (RootCategory $category, string $expected) {
    expect($category->cssColorVar())->toBe($expected);
})->with([
    [RootCategory::Image, '--color-cat-image'],
    [RootCategory::Audio, '--color-cat-audio'],
    [RootCategory::Games, '--color-cat-games'],
    [RootCategory::Applications, '--color-cat-applications'],
]);

test('returns row background class', function (RootCategory $category) {
    expect($category->rowBackgroundClass())->toBeString()->not->toBeEmpty();
})->with(RootCategory::cases());

test('games and applications prefer platform badge type', function (RootCategory $category) {
    expect($category->preferredBadgeTypes()[0])->toBe('platform');
})->with([RootCategory::Games, RootCategory::Applications]);

test('image and audio prefer format badge type', function (RootCategory $category) {
    expect($category->preferredBadgeTypes()[0])->toBe('format');
})->with([RootCategory::Image, RootCategory::Audio]);
