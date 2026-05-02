<?php

declare(strict_types=1);

use App\Data\SpotwebCategories;

test('category rows use the first Spotweb value as the display name', function () {
    $rows = SpotwebCategories::toCategoryRows();

    $divx = array_first(array_values(array_filter(
        $rows,
        fn (array $row): bool => $row['code'] === '01a00'
    )));

    expect($divx)
        ->not->toBeNull()
        ->and($divx['name'])->toBe('DivX')
        ->and($divx['slug'])->toBe('divx');
});
