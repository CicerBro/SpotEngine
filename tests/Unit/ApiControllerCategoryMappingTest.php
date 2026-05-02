<?php

declare(strict_types=1);

use App\Http\Controllers\ApiController;

test('newznab category mapping uses the first comma separated category', function () {
    $controller = new ReflectionClass(ApiController::class)->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(ApiController::class, 'mapNewznabCategory');
    $method->setAccessible(true);

    expect($method->invoke($controller, '2000,2040'))->toBe('01')
        ->and($method->invoke($controller, '4050,4000'))->toBe('03')
        ->and($method->invoke($controller, '7000,7010'))->toBe('01')
        ->and($method->invoke($controller, ''))->toBeNull();
});
