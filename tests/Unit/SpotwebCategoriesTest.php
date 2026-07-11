<?php

declare(strict_types=1);

use App\Data\SpotwebCategories;

function spotwebCategoriesUnitFixture(): string
{
    return <<<'PHP'
<?php

class SpotCategories
{
    public static $_head_categories = [
        0 => 'Image',
        1 => 'Sound',
        2 => 'Games',
        3 => 'Applications',
    ];

    public static $_subcat_descriptions = [
        0 => ['a' => 'Format', 'z' => 'Type'],
        1 => ['z' => 'Type'],
        2 => ['z' => 'Type'],
        3 => ['z' => 'Type'],
    ];

    public static $_categories = [
        0 => [
            'a' => [
                0 => ['DivX', ['z0'], ['z0']],
            ],
            'z' => [
                0 => 'Movie',
            ],
        ],
        1 => [],
        2 => [
            'z' => [
                'z' => 'everything',
            ],
        ],
        3 => [],
    ];
}
PHP;
}

test('category rows use the first Spotweb value as the display name', function () {
    $rows = SpotwebCategories::fromSpotCategoriesPhp(spotwebCategoriesUnitFixture());

    $divx = array_first(array_values(array_filter(
        $rows,
        fn (array $row): bool => $row['code'] === '01a00'
    )));

    expect($divx)
        ->not->toBeNull()
        ->and($divx['name'])->toBe('DivX')
        ->and($divx['slug'])->toBe('divx');
});

test('category rows parse string-only Spotweb z categories', function () {
    $rows = SpotwebCategories::fromSpotCategoriesPhp(spotwebCategoriesUnitFixture());

    $everything = array_first(array_values(array_filter(
        $rows,
        fn (array $row): bool => $row['code'] === '03z00'
    )));

    expect($everything)
        ->not->toBeNull()
        ->and($everything['name'])->toBe('everything')
        ->and($everything['type'])->toBe('type');
});

test('category parser rejects missing Spotweb arrays', function () {
    SpotwebCategories::fromSpotCategoriesPhp('<?php class SpotCategories {}');
})->throws(RuntimeException::class, 'Spotweb category property [_head_categories] was not found.');
