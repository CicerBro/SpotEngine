<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('spot:admin:create')]
#[Description('Interactively create the first SpotEngine administrator')]
class CreateAdminUser extends Command
{
    public function handle(CreateNewUser $createNewUser): int
    {
        if (User::query()->where('is_admin', true)->exists()) {
            $this->error('An administrator already exists. Manage additional administrators from the admin UI.');

            return self::FAILURE;
        }

        $username = (string) $this->ask('Username');
        $name = (string) $this->ask('Name');
        $email = (string) $this->ask('Email address');
        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $user = $createNewUser->create([
            'username' => $username,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ]);

        $user->forceFill(['is_admin' => true])->save();

        $this->info("Administrator [{$user->username}] created.");

        return self::SUCCESS;
    }
}
