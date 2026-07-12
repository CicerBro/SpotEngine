<?php

declare(strict_types=1);

use App\Console\Commands\RetrieveSpots;
use Illuminate\Console\CacheCommandMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('spotengine.nntp', [
        'host' => 'news.example.com',
        'port' => 563,
        'ssl' => true,
        'username' => 'user',
        'password' => 'pass',
        'timeout' => 60,
        'connections' => 20,
        'groups' => ['spots' => 'free.pt'],
    ]);
});

test('command fails when NNTP host is not configured', function () {
    Config::set('spotengine.nntp.host', '');

    $this->artisan('spot:retrieve')
        ->assertFailed()
        ->expectsOutputToContain('NNTP is not configured');
});

test('command fails when NNTP spots group is not configured', function () {
    Config::set('spotengine.nntp.groups.spots', '');

    $this->artisan('spot:retrieve')
        ->assertFailed()
        ->expectsOutputToContain('NNTP spots group is not configured');
});

test('command fails when only NNTP username is set without password', function () {
    Config::set('spotengine.nntp.password', '');

    $this->artisan('spot:retrieve')
        ->assertFailed()
        ->expectsOutputToContain('NNTP credentials are incomplete');
});

test('command fails when only NNTP password is set without username', function () {
    Config::set('spotengine.nntp.username', '');

    $this->artisan('spot:retrieve')
        ->assertFailed()
        ->expectsOutputToContain('NNTP credentials are incomplete');
});

test('command accepts backfill option and still validates config', function () {
    Config::set('spotengine.nntp.host', '');

    $this->artisan('spot:retrieve', ['--backfill' => true, '--limit' => 10000])
        ->assertFailed()
        ->expectsOutputToContain('NNTP is not configured');
});

test('clear-lock releases stuck command isolation lock', function () {
    $command = app(RetrieveSpots::class);
    $mutex = app(CacheCommandMutex::class);

    expect($mutex->create($command))->toBeTrue();

    $this->artisan('spot:retrieve', ['--clear-lock' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Released the command isolation lock');

    expect($mutex->exists($command))->toBeFalse();
});

test('clear-lock releases scheduler overlap lock for spot retrieve', function () {
    $schedule = app(Schedule::class);
    $event = collect($schedule->events())->first(
        fn ($event) => str_contains((string) ($event->command ?? ''), 'spot:retrieve'),
    );

    expect($event)->not->toBeNull();
    expect($event->mutex->create($event))->toBeTrue();

    $this->artisan('spot:retrieve', ['--clear-lock' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Released the scheduler overlap lock');

    expect($event->mutex->exists($event))->toBeFalse();
});

test('clear-lock succeeds when no locks are held', function () {
    $this->artisan('spot:retrieve', ['--clear-lock' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('No command isolation lock was found')
        ->expectsOutputToContain('No scheduler overlap lock was found');
});
