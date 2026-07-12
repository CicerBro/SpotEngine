<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('user model stores username and email in lowercase', function () {
    $user = User::factory()->create([
        'username' => 'Casey',
        'email' => 'Casey@Example.com',
    ]);

    expect($user->username)->toBe('casey')
        ->and($user->email)->toBe('casey@example.com');

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'username' => 'casey',
        'email' => 'casey@example.com',
    ]);
});

test('create new user normalizes identity before unique validation', function () {
    User::factory()->create(['username' => 'casey', 'email' => 'casey@example.com']);

    expect(fn () => app(CreateNewUser::class)->create([
        'username' => 'Casey',
        'name' => 'Duplicate User',
        'email' => 'Casey@Example.com',
        'password' => 'A-secure-password-123!',
        'password_confirmation' => 'A-secure-password-123!',
    ]))->toThrow(ValidationException::class);
});

test('user can log in with mixed case username', function () {
    User::factory()->create([
        'username' => 'casey',
        'password' => 'A-secure-password-123!',
    ]);

    $response = $this->post('/login', [
        'username' => 'Casey',
        'password' => 'A-secure-password-123!',
    ]);

    $response->assertRedirect();
    $this->assertAuthenticated();
});
