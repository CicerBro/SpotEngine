<?php

declare(strict_types=1);

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
