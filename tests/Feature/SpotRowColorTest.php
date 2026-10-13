<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Spot;
use Carbon\CarbonImmutable;

test('spot rows retain category colours regardless of read state', function (
    string $categoryCode,
    string $formatCode,
    string $formatName,
    string $colour,
    string $colourVariable,
    ?string $readUntil,
    string $titleWeight,
) {
    $format = Category::factory()->make([
        'code' => $formatCode,
        'name' => $formatName,
        'type' => 'format',
    ]);
    $spot = Spot::factory()->inCategory($categoryCode)->make([
        'id' => 1,
        'title' => 'Category coloured spot',
        'subcategories' => [$formatCode],
        'spot_posted_at' => '2026-10-13 12:00:00',
    ]);
    $spot->setRelation('category', null);

    $view = $this->view('partials.spots-table-row', [
        'spot' => $spot,
        'categoriesByCode' => collect([$formatCode => $format]),
        'spotsReadUntil' => $readUntil === null ? null : CarbonImmutable::parse($readUntil),
    ]);

    $view->assertSee($formatName)
        ->assertSee('background-color: var(' . $colourVariable . ')', false)
        ->assertSee('border-left: 3px solid var(' . $colourVariable . ')', false)
        ->assertSee('background-color: color-mix(in srgb, var(' . $colourVariable . ') 5%, transparent)', false)
        ->assertSee('rounded ' . $titleWeight . ' leading-tight', false);

    preg_match('/<tr class="([^"]*)">/', (string) $view, $row);
    $classes = explode(' ', $row[1]);
    $backgroundClasses = array_values(array_filter(
        $classes,
        fn (string $class): bool => str_starts_with($class, 'bg-') || str_starts_with($class, 'dark:bg-'),
    ));

    expect($backgroundClasses)->toBe([
        'bg-' . $colour . '-500/5',
        'dark:bg-' . $colour . '-500/[0.08]',
    ]);
})->with([
    'MP3 audio' => ['02', '02a00', 'MP3', 'amber', '--color-cat-audio'],
    'WMA audio' => ['02', '02a01', 'WMA', 'amber', '--color-cat-audio'],
    'WAV audio' => ['02', '02a02', 'WAV', 'amber', '--color-cat-audio'],
    'OGG audio' => ['02', '02a03', 'OGG', 'amber', '--color-cat-audio'],
    'EAC audio' => ['02', '02a04', 'EAC', 'amber', '--color-cat-audio'],
    'DTS audio' => ['02', '02a05', 'DTS', 'amber', '--color-cat-audio'],
    'AAC audio' => ['02', '02a06', 'AAC', 'amber', '--color-cat-audio'],
    'APE audio' => ['02', '02a07', 'APE', 'amber', '--color-cat-audio'],
    'FLAC audio' => ['02', '02a08', 'FLAC', 'amber', '--color-cat-audio'],
    'MPEG video' => ['01', '01a02', 'MPEG', 'blue', '--color-cat-image'],
    'ISO game' => ['03', '03a01', 'ISO', 'green', '--color-cat-games'],
    'application' => ['04', '04a00', 'Win', 'red', '--color-cat-applications'],
])->with([
    'unread' => ['2026-10-13 11:00:00', 'font-bold'],
    'read' => ['2026-10-13 13:00:00', 'font-normal'],
    'no read watermark' => [null, 'font-bold'],
]);
