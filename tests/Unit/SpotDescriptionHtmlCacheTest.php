<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\BbCodeParsingService;

afterEach(function () {
    app()->forgetInstance(BbCodeParsingService::class);
    \Mockery::close();
});

test('description_html accessor parses once per description value', function () {
    $parser = \Mockery::mock(BbCodeParsingService::class);
    $parser->shouldReceive('parse')
        ->once()
        ->with('[b]cached[/b]')
        ->andReturn('<strong>cached</strong>');

    app()->instance(BbCodeParsingService::class, $parser);

    $spot = new Spot(['description' => '[b]cached[/b]']);

    expect($spot->description_html)->toBe('<strong>cached</strong>');
    expect($spot->description_html)->toBe('<strong>cached</strong>');
});

test('description_html accessor cache invalidates when description changes', function () {
    $parser = \Mockery::mock(BbCodeParsingService::class);
    $parser->shouldReceive('parse')->once()->with('first')->andReturn('<strong>first</strong>');
    $parser->shouldReceive('parse')->once()->with('second')->andReturn('<strong>second</strong>');

    app()->instance(BbCodeParsingService::class, $parser);

    $spot = new Spot(['description' => 'first']);

    expect($spot->description_html)->toBe('<strong>first</strong>');

    $spot->description = 'second';

    expect($spot->description_html)->toBe('<strong>second</strong>');
    expect($spot->description_html)->toBe('<strong>second</strong>');
});
