<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Models\Category;
use App\Models\Spot;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Cache::forget('categories.all');
});

test('show uses cached categories to resolve subcategory names', function () {
    Cache::put('categories.all', collect([
        new Category(['code' => '01z01', 'name' => 'Series', 'type' => 'type']),
        new Category(['code' => '01z02', 'name' => 'Book', 'type' => 'type']),
        new Category(['code' => '01z03', 'name' => '-', 'type' => 'type']),
    ]));

    $spot = new Spot([
        'subcategories' => ['z1', 'z2', 'z3', 'z9'],
        'category_code' => '01',
    ]);
    $spot->setRelation('category', null);

    $view = app(HomeController::class)->show($spot);

    expect($view->getData()['subcategoryNames']->all())->toBe([
        'z1' => 'Series',
        'z2' => 'Book',
        'z9' => 'z9',
    ]);
    expect($view->getData()['badgeLabel'])->toBe('Series');
});
