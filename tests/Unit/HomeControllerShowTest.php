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
        new Category(['code' => 'z1', 'name' => 'Movies']),
        new Category(['code' => 'z2', 'name' => 'HD']),
        new Category(['code' => 'z3', 'name' => '-']),
    ]));

    $spot = new Spot([
        'subcategories' => ['z1', 'z2', 'z3', 'z9'],
        'category_code' => '0',
    ]);
    $spot->setRelation('category', null);

    $view = app(HomeController::class)->show($spot);

    expect($view->getData()['subcategoryNames']->all())->toBe([
        'z1' => 'Movies',
        'z2' => 'HD',
        'z9' => 'z9',
    ]);
});
