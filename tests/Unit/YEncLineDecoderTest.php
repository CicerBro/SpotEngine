<?php

declare(strict_types=1);

use App\Services\Nntp\YEncLineDecoder;

test('all byte values are decoded in bulk', function () {
    $bytes = range(0, 255);
    $encoded = '';

    foreach ($bytes as $byte) {
        $encodedByte = ($byte + 42) & 0xFF;
        $encoded .= in_array($encodedByte, [0, 9, 10, 13, 46, 61], true)
            ? '='.chr(($encodedByte + 64) & 0xFF)
            : chr($encodedByte);
    }

    expect((new YEncLineDecoder)->decode($encoded))
        ->toBe(pack('C*', ...$bytes));
});

test('escaped yEnc bytes are decoded', function () {
    $decoded = (new YEncLineDecoder)->decode('=@=I=J=M=n=}');

    expect($decoded)->toBe(pack('C*', 214, 223, 224, 227, 4, 19));
});

test('incomplete trailing escapes are rejected in strict mode', function () {
    (new YEncLineDecoder)->decode('k=');
})->throws(UnexpectedValueException::class, 'incomplete escape');

test('incomplete trailing escapes are ignored in lenient mode', function () {
    $decoded = (new YEncLineDecoder)->decode('k=', rejectIncompleteEscape: false);

    expect($decoded)->toBe('A');
});

test('incomplete escapes are handled at physical line boundaries', function () {
    $decoder = new YEncLineDecoder;

    expect($decoder->decodeLines(['k=', 'l'], rejectIncompleteEscape: false))
        ->toBe('AB');

    $decoder->decodeLines(['k=', 'l']);
})->throws(UnexpectedValueException::class, 'incomplete escape');
