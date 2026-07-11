<?php

declare(strict_types=1);

test('nestedCachePath creates two-level nested path from hash', function () {
    $result = nestedCachePath('/base/path', 'a3f2abc9d1e4', 'nzb');

    expect($result)->toBe('/base/path' . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . '3' . DIRECTORY_SEPARATOR . 'a3f2abc9d1e4.nzb');
});

test('nestedCachePath works with different extensions', function () {
    $result = nestedCachePath('/cache', 'ff00aabb', 'img');

    expect($result)->toBe('/cache' . DIRECTORY_SEPARATOR . 'f' . DIRECTORY_SEPARATOR . 'f' . DIRECTORY_SEPARATOR . 'ff00aabb.img');
});

test('nestedCachePath uses first two characters of hash for directory nesting', function () {
    $hash = md5('test-value');
    $result = nestedCachePath('/tmp', $hash, 'dat');

    expect($result)->toContain(DIRECTORY_SEPARATOR . $hash[0] . DIRECTORY_SEPARATOR . $hash[1] . DIRECTORY_SEPARATOR);
    expect($result)->toEndWith($hash . '.dat');
});
