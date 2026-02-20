<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Flush sessions so existing browser sessions are invalidated after a re-seed.
        $sessionDriver = config('session.driver');

        if ($sessionDriver === 'database') {
            $connection = config('session.connection') ?? config('database.default');
            $table = config('session.table', 'sessions');
            DB::connection($connection)->table($table)->truncate();
        } elseif ($sessionDriver === 'redis') {
            // Sessions use session.connection (defaults to 'default' = Redis DB 0), NOT the cache
            // store's connection. Cache::store('redis')->clear() flushes DB 1, so we must clear
            // the correct Redis connection directly.
            $connection = config('session.connection') ?? 'default';
            Redis::connection($connection)->flushDb();
        }

        $this->call([
            CategorySeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
