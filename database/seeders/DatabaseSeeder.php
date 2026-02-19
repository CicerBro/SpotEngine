<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Redis;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Flush sessions so existing browser sessions are invalidated after a re-seed.
        // Sessions live in Redis DB 0; cache lives in DB 1, so this is safe.
        Redis::connection('default')->flushDb();

        $this->call([
            CategorySeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
