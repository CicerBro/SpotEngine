<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

test('models remain guarded after application boot', function () {
    expect(Model::isUnguarded())->toBeFalse()
        ->and(fn () => (new User)->fill(['unexpected_admin_flag' => true]))
        ->toThrow(MassAssignmentException::class);
});

test('registration routes are disabled by default', function () {
    expect(config('spotengine.registration_open'))->toBeFalse()
        ->and(config('fortify.features'))->toContain(Features::registration())
        ->and(Route::has('register'))->toBeTrue()
        ->and(Route::has('register.store'))->toBeTrue();

    $this->get('/register')->assertNotFound();
    $this->post('/register', [
        'username' => 'blocked-user',
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
        'password' => 'A-secure-password-123!',
        'password_confirmation' => 'A-secure-password-123!',
    ])->assertNotFound();

    config(['spotengine.registration_open' => true]);

    $this->get('/register')->assertSuccessful();
});
