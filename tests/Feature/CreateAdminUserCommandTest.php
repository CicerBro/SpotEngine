<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the first administrator is created with explicitly supplied credentials', function () {
    $this->artisan('spot:admin:create')
        ->expectsQuestion('Username', 'First-Admin')
        ->expectsQuestion('Name', 'First Administrator')
        ->expectsQuestion('Email address', 'Admin@Example.com')
        ->expectsQuestion('Password', 'A-secure-password-123!')
        ->expectsQuestion('Confirm password', 'A-secure-password-123!')
        ->expectsOutput('Administrator [first-admin] created.')
        ->assertSuccessful();

    $admin = User::query()->where('username', 'first-admin')->firstOrFail();

    expect($admin->is_admin)->toBeTrue()
        ->and($admin->email)->toBe('admin@example.com')
        ->and(password_verify('A-secure-password-123!', $admin->password))->toBeTrue();
});

test('the bootstrap command refuses to create another administrator', function () {
    $existingAdmin = User::factory()->admin()->create();

    $this->artisan('spot:admin:create')
        ->expectsOutput('An administrator already exists. Manage additional administrators from the admin UI.')
        ->assertFailed();

    expect(User::query()->where('is_admin', true)->count())->toBe(1);
    $this->assertModelExists($existingAdmin);
});
