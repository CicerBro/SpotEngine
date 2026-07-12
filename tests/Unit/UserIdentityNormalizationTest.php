<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('user model stores username and email in lowercase', function () {
    $user = User::factory()->create([
        'username' => 'Bento',
        'email' => 'Bento@Example.com',
    ]);

    expect($user->username)->toBe('bento')
        ->and($user->email)->toBe('bento@example.com');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'username' => 'bento',
        'email' => 'bento@example.com',
    ]);
});

test('create new user normalizes identity before unique validation', function () {
    User::factory()->create(['username' => 'bento', 'email' => 'bento@example.com']);

    expect(fn () => app(CreateNewUser::class)->create([
        'username' => 'Bento',
        'name' => 'Duplicate User',
        'email' => 'Bento@Example.com',
        'password' => 'A-secure-password-123!',
        'password_confirmation' => 'A-secure-password-123!',
    ]))->toThrow(ValidationException::class);
});

test('user can log in with mixed case username', function () {
    User::factory()->create([
        'username' => 'bento',
        'password' => 'A-secure-password-123!',
    ]);

    $response = $this->post('/login', [
        'username' => 'Bento',
        'password' => 'A-secure-password-123!',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated();
});
