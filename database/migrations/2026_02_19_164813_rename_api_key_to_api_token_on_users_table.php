<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'api_key')) {
            Schema::table('users', function (Blueprint $table) {
                $table->renameColumn('api_key', 'api_token');
            });

            // Regenerate all existing tokens to the new shorter format
            DB::table('users')->get()->each(function (object $user) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['api_token' => bin2hex(random_bytes(16))]);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->string('api_token', 32)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_token', 64)->change();
            $table->renameColumn('api_token', 'api_key');
        });
    }
};
