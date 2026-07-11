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
            $encoded .= '='.chr(($value + 64) & 0xFF);
        } else {
            $encoded .= chr($value);
        }
    }

    return "=ybegin part={$part} line=128 size=".strlen($data)." name=preview.jpg\r\n"
        .'=ypart begin=1 end='.strlen($data)."\r\n"
        .$encoded."\r\n"
        .'=yend size='.strlen($data).' part='.$part.' pcrc32='.hash('crc32b', $data);
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
        "Content-Type: image/png\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('base64-image'),
        'base64-image',
    ],
    'uuencode' => [
        "begin 644 preview.jpg\r\n".rtrim(convert_uuencode('uu-image'))."\r\nend",
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
