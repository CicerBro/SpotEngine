<?php

declare(strict_types=1);

test('formats zero as 0.00 B', function () {
    expect(formatBytes(0))->toBe('0.00 B');
});

test('formats bytes', function () {
    expect(formatBytes(500))->toBe('500.00 B');
});

test('formats kilobytes', function () {
    expect(formatBytes(1024))->toBe('1.00 KB');
    expect(formatBytes(1536))->toBe('1.50 KB');
});

test('formats megabytes', function () {
    expect(formatBytes(1024 * 1024))->toBe('1.00 MB');
    expect(formatBytes(5 * 1024 * 1024))->toBe('5.00 MB');
});

test('formats gigabytes', function () {
    expect(formatBytes(1024 * 1024 * 1024))->toBe('1.00 GB');
    expect(formatBytes(3398))->toBe('3.32 KB');
});

test('accepts string and float input', function () {
    expect(formatBytes('2048'))->toBe('2.00 KB');
    expect(formatBytes(1024.0))->toBe('1.00 KB');
});

test('uses custom decimals', function () {
    expect(formatBytes(1536, 0))->toBe('2 KB');
    expect(formatBytes(1536, 3))->toBe('1.500 KB');
});

test('negative size becomes zero', function () {
    expect(formatBytes(-100))->toBe('0.00 B');
});
