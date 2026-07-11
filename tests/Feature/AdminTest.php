<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin routes require authentication', function () {
    $response = $this->get(route('admin.index'));

    $response->assertRedirect();
});

test('admin routes forbid non-admin users', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $response = $this->actingAs($user)->get(route('admin.index'));

    $response->assertForbidden();
});

test('admin can view dashboard', function () {
    $admin = User::factory()->admin()->create();
    $latestSpotTime = now()->subHour()->startOfSecond();
    Spot::factory()->inCategory('01')->create(['spot_posted_at' => $latestSpotTime->copy()->subDay()]);
    Spot::factory()->inCategory('01')->create(['spot_posted_at' => $latestSpotTime]);

    $response = $this->actingAs($admin)->get(route('admin.index'));

    $response->assertSuccessful();
    $response->assertViewIs('admin.index');
    $response->assertViewHas('stats');
    expect($response->viewData('stats')['total_spots'])->toBe(2);

    $categoryStats = $response->viewData('stats')['category_stats'];
    $firstCategory = $categoryStats->first();

    expect($firstCategory)->not->toBeNull();
    expect($firstCategory->category_code)->toBe('01');
    expect($firstCategory->category_name)->toBe('Image');
    expect($firstCategory->count)->toBe(2);
    expect(Carbon::parse($firstCategory->latest)->toDateTimeString())
        ->toBe($latestSpotTime->toDateTimeString());
});

test('admin can view users list', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();

    $response = $this->actingAs($admin)->get(route('admin.users'));

    $response->assertSuccessful();
    $response->assertViewHas('users');
});

test('admin can create user', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->post(route('admin.users.create'), [
        'username' => 'newuser',
        'name' => 'New User',
        'email' => 'newuser@example.com',
        'password' => 'password123',
        'is_admin' => false,
    ]);

    $response->assertRedirect(route('admin.users'));
    $response->assertSessionHas('success');
    $this->assertDatabaseHas('users', ['username' => 'newuser', 'email' => 'newuser@example.com']);
});

test('admin cannot delete own account', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->delete(route('admin.users.delete', $admin));

    $response->assertForbidden();
    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('admin can delete another user', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    $response = $this->actingAs($admin)->delete(route('admin.users.delete', $other));

    $response->assertRedirect(route('admin.users'));
    $response->assertSessionHas('success');
    $this->assertDatabaseMissing('users', ['id' => $other->id]);
});

test('admin clean deletes old spots', function () {
    $admin = User::factory()->admin()->create();
    $oldSpot = Spot::factory()->create(['spot_posted_at' => now()->subDays(60)]);
    $recentSpot = Spot::factory()->create(['spot_posted_at' => now()->subDays(5)]);

    $response = $this->actingAs($admin)->post(route('admin.clean'), ['days' => 30]);

    $response->assertRedirect(route('admin.index'));
    $response->assertSessionHas('success');
    $this->assertDatabaseMissing('spots', ['id' => $oldSpot->id]);
    $this->assertDatabaseHas('spots', ['id' => $recentSpot->id]);
});
