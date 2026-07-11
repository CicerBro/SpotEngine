<?php

declare(strict_types=1);

use App\Services\OverlappedSpotRetrieverService;
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

test('command warns when forward new-to-old retrieval cannot checkpoint mid-run', function () {
    Config::set('spotengine.retrieval.forward_new_to_old', true);

    $this->mock(OverlappedSpotRetrieverService::class, function ($mock): void {
        $mock->shouldReceive('retrieve')->once()->andReturn([
            'processed' => 0,
            'inserted' => 0,
            'last_article' => 0,
        ]);
    });

    $this->artisan('spot:retrieve')
        ->assertSuccessful()
        ->expectsOutputToContain('RETRIEVAL_FORWARD_NEW_TO_OLD')
        ->expectsOutputToContain('Do not quit halfway');
});

test('command does not warn about new-to-old checkpointing during backfill', function () {
    Config::set('spotengine.retrieval.forward_new_to_old', true);

    $this->mock(OverlappedSpotRetrieverService::class, function ($mock): void {
        $mock->shouldReceive('retrieve')->once()->andReturn([
            'processed' => 0,
            'inserted' => 0,
            'last_article' => 0,
        ]);
    });

    $this->artisan('spot:retrieve', ['--backfill' => true])
        ->assertSuccessful()
        ->doesntExpectOutputToContain('RETRIEVAL_FORWARD_NEW_TO_OLD');
});

test('command mentions long initial scans in new-to-old checkpoint warning', function () {
    Config::set('spotengine.retrieval.forward_new_to_old', true);

    $this->mock(OverlappedSpotRetrieverService::class, function ($mock): void {
        $mock->shouldReceive('retrieve')->once()->andReturn([
            'processed' => 0,
            'inserted' => 0,
            'last_article' => 0,
        ]);
    });

    $this->artisan('spot:retrieve', ['--initial-scan' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Initial scans can take hours');
});
