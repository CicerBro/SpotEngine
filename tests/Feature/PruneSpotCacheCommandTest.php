<?php

declare(strict_types=1);

beforeEach(function () {
    $this->cachePath = storage_path('app/cache/test-prune-'.uniqid());
    mkdir($this->cachePath, 0755, true);
    config(['spotengine.cache.nzb_path' => $this->cachePath]);
    config(['spotengine.cache.image_path' => $this->cachePath]);
});

afterEach(function () {
    if (is_dir($this->cachePath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->cachePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->cachePath);
    }
});

test('prune-cache skips pruning when retention is 0', function () {
    $file = $this->cachePath.'/old-file.nzb';
    file_put_contents($file, 'data');
    touch($file, time() - 86400 * 60);

    $this->artisan('spot:prune-cache', ['--nzb-days' => 0, '--image-days' => 0])
        ->assertSuccessful()
        ->expectsOutputToContain('NZB pruning disabled')
        ->expectsOutputToContain('Image pruning disabled');

    expect(file_exists($file))->toBeTrue();
});

test('prune-cache deletes old files in nested directories', function () {
    $nestedDir = $this->cachePath.'/a/3';
    mkdir($nestedDir, 0755, true);

    $oldFile = $nestedDir.'/a3f2abc9.nzb';
    file_put_contents($oldFile, 'old-data');
    touch($oldFile, time() - 86400 * 60);

    $newFile = $nestedDir.'/b4e5cdef.nzb';
    file_put_contents($newFile, 'new-data');

    $this->artisan('spot:prune-cache', ['--nzb-days' => 30, '--image-days' => 30])
        ->assertSuccessful();

    expect(file_exists($oldFile))->toBeFalse();
    expect(file_exists($newFile))->toBeTrue();
});

test('prune-cache removes empty nested directories after pruning', function () {
    $nestedDir = $this->cachePath.'/f/a';
    mkdir($nestedDir, 0755, true);

    $oldFile = $nestedDir.'/fa1234.nzb';
    file_put_contents($oldFile, 'data');
    touch($oldFile, time() - 86400 * 60);

    $this->artisan('spot:prune-cache', ['--nzb-days' => 30, '--image-days' => 30])
        ->assertSuccessful();

    expect(is_dir($nestedDir))->toBeFalse();
    expect(is_dir($this->cachePath.'/f'))->toBeFalse();
    expect(is_dir($this->cachePath))->toBeTrue();
});

test('prune-cache still handles flat files at root level', function () {
    $oldFile = $this->cachePath.'/legacy-flat-file.nzb';
    file_put_contents($oldFile, 'old-data');
    touch($oldFile, time() - 86400 * 60);

    $this->artisan('spot:prune-cache', ['--nzb-days' => 30, '--image-days' => 30])
        ->assertSuccessful();

    expect(file_exists($oldFile))->toBeFalse();
});
