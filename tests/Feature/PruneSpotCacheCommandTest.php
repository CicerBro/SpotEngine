<?php

declare(strict_types=1);

beforeEach(function () {
    $this->nzbCachePath = storage_path('app/cache/test-prune-nzb-' . uniqid());
    $this->imageCachePath = storage_path('app/cache/test-prune-img-' . uniqid());
    mkdir($this->nzbCachePath, 0755, true);
    mkdir($this->imageCachePath, 0755, true);
    config(['spotengine.cache.nzb_path' => $this->nzbCachePath]);
    config(['spotengine.cache.image_path' => $this->imageCachePath]);

    // Alias for backwards compat with existing tests that use a single path
    $this->cachePath = $this->nzbCachePath;
});

afterEach(function () {
    foreach ([$this->nzbCachePath, $this->imageCachePath] as $path) {
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($path);
        }
    }
});

test('prune-cache skips pruning when retention is 0', function () {
    $file = $this->cachePath . '/old-file.nzb';
    file_put_contents($file, 'data');
    touch($file, time() - 86400 * 60);

    $this->artisan('spot:prune-cache', ['--nzb-days' => 0, '--image-days' => 0])
        ->assertSuccessful()
        ->expectsOutputToContain('NZB pruning disabled')
        ->expectsOutputToContain('Image pruning disabled');

    expect(file_exists($file))->toBeTrue();
});

test('prune-cache deletes old files in nested directories', function () {
    $nestedDir = $this->cachePath . '/a/3';
    mkdir($nestedDir, 0755, true);

    $oldFile = $nestedDir . '/a3f2abc9.nzb';
    file_put_contents($oldFile, 'old-data');
    touch($oldFile, time() - 86400 * 60);

    $newFile = $nestedDir . '/b4e5cdef.nzb';
    file_put_contents($newFile, 'new-data');

    $this->artisan('spot:prune-cache', ['--nzb-days' => 30, '--image-days' => 30])
        ->assertSuccessful();

    expect(file_exists($oldFile))->toBeFalse();
    expect(file_exists($newFile))->toBeTrue();
});

test('prune-cache removes empty nested directories after pruning', function () {
    $nestedDir = $this->cachePath . '/f/a';
    mkdir($nestedDir, 0755, true);

    $oldFile = $nestedDir . '/fa1234.nzb';
    file_put_contents($oldFile, 'data');
    touch($oldFile, time() - 86400 * 60);

    $this->artisan('spot:prune-cache', ['--nzb-days' => 30, '--image-days' => 30])
        ->assertSuccessful();

    expect(is_dir($nestedDir))->toBeFalse();
    expect(is_dir($this->cachePath . '/f'))->toBeFalse();
    expect(is_dir($this->cachePath))->toBeTrue();
});

test('prune-cache still handles flat files at root level', function () {
    $oldFile = $this->cachePath . '/legacy-flat-file.nzb';
    file_put_contents($oldFile, 'old-data');
    touch($oldFile, time() - 86400 * 60);

    $this->artisan('spot:prune-cache', ['--nzb-days' => 30, '--image-days' => 30])
        ->assertSuccessful();

    expect(file_exists($oldFile))->toBeFalse();
});

test('clear deletes all files regardless of age when confirmed', function () {
    // Create nested files in both cache directories
    $nzbDir = $this->nzbCachePath . '/a/3';
    mkdir($nzbDir, 0755, true);
    file_put_contents($nzbDir . '/recent.nzb', 'data');
    file_put_contents($nzbDir . '/old.nzb', 'data');

    $imgDir = $this->imageCachePath . '/b/4';
    mkdir($imgDir, 0755, true);
    file_put_contents($imgDir . '/recent.img', 'data');

    $this->artisan('spot:prune-cache', ['--clear' => true])
        ->expectsConfirmation('Are you sure you want to clear the entire cache?', 'yes')
        ->assertSuccessful()
        ->expectsOutputToContain('Cache cleared');

    expect(file_exists($nzbDir . '/recent.nzb'))->toBeFalse();
    expect(file_exists($nzbDir . '/old.nzb'))->toBeFalse();
    expect(file_exists($imgDir . '/recent.img'))->toBeFalse();
});

test('clear aborts when user declines confirmation', function () {
    $file = $this->nzbCachePath . '/keep-me.nzb';
    file_put_contents($file, 'data');

    $this->artisan('spot:prune-cache', ['--clear' => true])
        ->expectsConfirmation('Are you sure you want to clear the entire cache?', 'no')
        ->assertSuccessful()
        ->expectsOutputToContain('Aborted');

    expect(file_exists($file))->toBeTrue();
});

test('clear reports empty cache when no files exist', function () {
    $this->artisan('spot:prune-cache', ['--clear' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Cache is already empty');
});
