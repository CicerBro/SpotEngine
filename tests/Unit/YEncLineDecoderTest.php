<?php

declare(strict_types=1);

use App\Services\Nntp\YEncLineDecoder;

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
