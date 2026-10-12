<?php

declare(strict_types=1);

use App\Services\Nntp\SpotImageDecoder;

/**
 * @return string A complete yEnc NNTP article body.
 */
function encodeSpotImageYEnc(string $data, int $part): string
{
    $encoded = '';

    foreach (str_split($data) as $byte) {
        $value = (ord($byte) + 42) & 0xFF;

        if (\in_array($value, [0, 9, 10, 13, 46, 61], true)) {
            $encoded .= '=' . chr(($value + 64) & 0xFF);
        } else {
            $encoded .= chr($value);
        }
    }

    return "=ybegin part={$part} line=128 size=" . strlen($data) . " name=preview.jpg\r\n"
        . '=ypart begin=1 end=' . strlen($data) . "\r\n"
        . $encoded . "\r\n"
        . '=yend size=' . strlen($data) . ' part=' . $part . ' pcrc32=' . hash('crc32b', $data);
}

test('Spotnet multipart image fixtures discard NNTP framing before special decoding', function () {
    /** @var array{expected_png_base64: string, spotnet_multipart_body_base64: list<string>} $fixture */
    $fixture = require base_path('tests/Fixtures/Nntp/spot-image-payloads.php');
    $bodies = array_map(
        static fn (string $body): string => (string) base64_decode($body, true),
        $fixture['spotnet_multipart_body_base64'],
    );

    $decoded = (new SpotImageDecoder)->decode($bodies);

    expect(base64_encode($decoded))->toBe($fixture['expected_png_base64'])
        ->and(getimagesizefromstring($decoded)['mime'])->toBe('image/png');
});

test('multipart yEnc image parts are checksum validated and assembled by part number', function () {
    $expected = "\xff\xd8first-second\xff\xd9";
    $first = substr($expected, 0, 8);
    $second = substr($expected, 8);

    $decoded = (new SpotImageDecoder)->decode([
        encodeSpotImageYEnc($second, 2),
        encodeSpotImageYEnc($first, 1),
    ]);

    expect($decoded)->toBe($expected);
});

test('explicit base64 and uuencoded image bodies are decoded', function (string $body, string $expected) {
    expect((new SpotImageDecoder)->decode([$body]))->toBe($expected);
})->with([
    'base64' => [
        "Content-Type: image/png\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('base64-image'),
        'base64-image',
    ],
    'uuencode' => [
        "begin 644 preview.jpg\r\n" . rtrim(convert_uuencode('uu-image')) . "\r\nend",
        'uu-image',
    ],
]);

test('corrupt yEnc checksums are rejected', function () {
    $body = preg_replace(
        '/pcrc32=[a-f0-9]+/',
        'pcrc32=00000000',
        encodeSpotImageYEnc('corrupt', 1),
    );

    (new SpotImageDecoder)->decode([(string) $body]);
})->throws(UnexpectedValueException::class, 'checksum');

test('incomplete trailing yEnc escapes are rejected for images', function () {
    $body = "=ybegin line=128 size=0 name=preview.jpg\r\n"
        . "=\r\n"
        . '=yend size=0';

    (new SpotImageDecoder)->decode([$body]);
})->throws(UnexpectedValueException::class, 'incomplete escape');

test('bounds decoded images in every supported encoding', function (string $format) {
    config()->set('spotengine.payload_limits.image_decoded_bytes', 5);
    $data = 'sixsix';
    $body = match ($format) {
        'yenc' => encodeSpotImageYEnc($data, 1),
        'base64' => "Content-Transfer-Encoding: base64\r\n\r\n" . base64_encode($data),
        'uuencode' => "begin 644 preview.jpg\r\n" . rtrim(convert_uuencode($data)) . "\r\nend",
        default => $data,
    };

    expect(fn () => (new SpotImageDecoder)->decode([$body]))
        ->toThrow(UnexpectedValueException::class, 'decoded image exceeds');
})->with(['yenc', 'base64', 'uuencode', 'spotnet']);

test('accepts decoded images exactly at the cumulative limit', function () {
    config()->set('spotengine.payload_limits.image_decoded_bytes', 6);

    expect((new SpotImageDecoder)->decode(['first', 'x']))->toBe('firstx');
});

test('stops requesting image bodies once a cumulative payload limit is reached', function (string $limit, int $maximum, array $bodies) {
    config()->set('spotengine.payload_limits.' . $limit, $maximum);
    $yielded = 0;
    $articles = (function () use (&$yielded, $bodies): Generator {
        foreach ($bodies as $body) {
            $yielded++;
            yield $body;
        }
    })();

    expect(fn () => (new SpotImageDecoder)->decode($articles))->toThrow(UnexpectedValueException::class, 'payload limit');
    expect($yielded)->toBe(2);
})->with([
    'input bytes' => ['image_input_bytes', 7, ['first', 'next', 'never']],
    'decoded bytes' => ['image_decoded_bytes', 7, ['first', 'next', 'never']],
    'line count' => ['nntp_response_lines', 3, ["a\nb", "c\nd", 'never']],
    'segments' => ['image_segments', 1, ['first', 'next', 'never']],
]);

test('bounds images with escaped Spotnet bytes after reversing escapes', function () {
    config()->set('spotengine.payload_limits.image_decoded_bytes', 3);

    expect((new SpotImageDecoder)->decode(['=A=B=C']))->toBe("\0\r\n");
});
