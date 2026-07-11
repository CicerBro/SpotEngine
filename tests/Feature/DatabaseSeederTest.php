<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('normal seeding preserves sessions and does not create known administrator credentials', function () {
    DB::table('sessions')->insert([
        'id' => 'existing-session',
        'payload' => 'session-payload',
        'last_activity' => now()->timestamp,
    ]);

    $this->seed(DatabaseSeeder::class);

    expect(DB::table('sessions')->where('id', 'existing-session')->exists())->toBeTrue()
        ->and(User::query()->where('is_admin', true)->exists())->toBeFalse()
        ->and(Category::query()->exists())->toBeTrue();
});
