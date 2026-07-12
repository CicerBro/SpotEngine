<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array USER_FOREIGN_KEYS = [
        'user_downloads_user_id_foreign',
        'user_filters_user_id_foreign',
    ];

    public function up(): void
    {
        foreach (self::USER_FOREIGN_KEYS as $constraint) {
            DB::statement('ALTER TABLE ' . $this->foreignKeyTable($constraint) . ' DROP CONSTRAINT IF EXISTS ' . $constraint);
        }

        Schema::rename('users', 'users_old');

        $this->renameUsersIndexesToOld();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->string('api_token', 32)->nullable()->unique();
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('spots_read_until')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            INSERT INTO users (
                id, username, name, email, password, is_admin, api_token, remember_token,
                two_factor_secret, two_factor_recovery_codes, email_verified_at, last_login_at,
                spots_read_until, two_factor_confirmed_at, created_at, updated_at
            )
            SELECT
                id, username, name, email, password, is_admin, api_token, remember_token,
                two_factor_secret, two_factor_recovery_codes, email_verified_at, last_login_at,
                spots_read_until, two_factor_confirmed_at, created_at, updated_at
            FROM users_old
            SQL);

        DB::statement("SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 1))");

        Schema::drop('users_old');

        DB::statement('ALTER TABLE user_downloads ADD CONSTRAINT user_downloads_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE user_filters ADD CONSTRAINT user_filters_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        foreach (self::USER_FOREIGN_KEYS as $constraint) {
            DB::statement('ALTER TABLE ' . $this->foreignKeyTable($constraint) . ' DROP CONSTRAINT IF EXISTS ' . $constraint);
        }

        Schema::rename('users', 'users_old');

        $this->renameUsersIndexesToOld();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->string('api_token', 32)->nullable()->unique();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('spots_read_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        DB::statement(<<<'SQL'
            INSERT INTO users (
                id, username, name, email, email_verified_at, password, is_admin, api_token,
                last_login_at, spots_read_until, remember_token, created_at, updated_at,
                two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at
            )
            SELECT
                id, username, name, email, email_verified_at, password, is_admin, api_token,
                last_login_at, spots_read_until, remember_token, created_at, updated_at,
                two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at
            FROM users_old
            SQL);

        DB::statement("SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 1))");

        Schema::drop('users_old');

        DB::statement('ALTER TABLE user_downloads ADD CONSTRAINT user_downloads_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE user_filters ADD CONSTRAINT user_filters_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    }

    private function foreignKeyTable(string $constraint): string
    {
        return match ($constraint) {
            'user_downloads_user_id_foreign' => 'user_downloads',
            'user_filters_user_id_foreign' => 'user_filters',
            default => throw new InvalidArgumentException("Unknown foreign key constraint [{$constraint}]."),
        };
    }

    private function renameUsersIndexesToOld(): void
    {
        DB::statement('ALTER INDEX users_pkey RENAME TO users_old_pkey');
        DB::statement('ALTER INDEX users_username_unique RENAME TO users_old_username_unique');
        DB::statement('ALTER INDEX users_email_unique RENAME TO users_old_email_unique');
        DB::statement('ALTER INDEX users_api_token_unique RENAME TO users_old_api_token_unique');
    }
};
