<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

test('fresh installations can persist database cache entries and acquire locks', function () {
    $cache = Cache::store('database');

    $cache->put('infrastructure-test', ['count' => 3], 60);
    $lock = $cache->lock('infrastructure-lock', 60);
    $competingLock = $cache->lock('infrastructure-lock', 60);

    expect($cache->get('infrastructure-test'))->toBe(['count' => 3]);
    expect($lock->get())->toBeTrue();
    expect($competingLock->get())->toBeFalse();
    expect($lock->release())->toBeTrue();
    expect($competingLock->get())->toBeTrue();
    $competingLock->release();
});

test('fresh installations can enqueue and reserve database jobs', function () {
    $queue = Queue::connection('database');
    $payload = '{"job":"infrastructure-test","data":{}}';

    $queue->pushRaw($payload, 'infrastructure-test');
    $job = $queue->pop('infrastructure-test');

    expect($job)->not->toBeNull();
    expect($job->getRawBody())->toBe($payload);
    expect($job->attempts())->toBe(1);
    $job->delete();
    expect($queue->size('infrastructure-test'))->toBe(0);
});

test('repairing a missing infrastructure table preserves the other tables', function (string $missingTable) {
    $cache = Cache::store('database');
    $queue = Queue::connection('database');
    $cache->put('existing-cache-entry', 'preserved', 60);
    $lock = $cache->lock('existing-lock', 60);
    $lock->get();
    $queue->pushRaw('{"job":"existing-job","data":{}}', 'existing-work');
    Schema::drop($missingTable);
    $migration = require database_path('migrations/2026_10_12_172633_restore_missing_cache_and_queue_tables.php');

    $migration->up();
    $migration->up();
    $migration->down();

    if ($missingTable !== 'cache') {
        expect($cache->get('existing-cache-entry'))->toBe('preserved');
    }
    if ($missingTable !== 'cache_locks') {
        expect($cache->lock('existing-lock', 60)->get())->toBeFalse();
    }
    if ($missingTable !== 'jobs') {
        expect($queue->size('existing-work'))->toBe(1);
    }

    $cache->put('repaired-cache-entry', 'available', 60);
    expect($cache->get('repaired-cache-entry'))->toBe('available');
    $repairedLock = $cache->lock('repaired-lock', 60);
    expect($repairedLock->get())->toBeTrue();
    $repairedLock->release();
    $queue->pushRaw('{"job":"repaired-job","data":{}}', 'repaired-work');
    expect($queue->size('repaired-work'))->toBe(1);
})->with(['cache', 'cache_locks', 'jobs']);
