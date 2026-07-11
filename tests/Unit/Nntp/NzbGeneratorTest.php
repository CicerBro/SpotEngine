<?php

declare(strict_types=1);

use App\Services\Nntp\NzbGenerator;
use App\Services\Nntp\SingleNntpDriver;

function yEncArticle(string $data, string $name): string
{
    $encoded = '';

    foreach (unpack('C*', $data) as $byte) {
        $encodedByte = ($byte + 42) % 256;

        if (in_array($encodedByte, [0, 10, 13, 46, 61], true)) {
            $encoded .= '='.chr(($encodedByte + 64) % 256);
        } else {
            $encoded .= chr($encodedByte);
        }
    }

    return '=ybegin line=128 size='.strlen($data)." name={$name}\r\n"
        .$encoded."\r\n"
        .'=yend size='.strlen($data);
}

test('fetchNzb decodes multiple yEnc BODY responses without joining control lines', function () {
    $xml = '<?xml version="1.0"?><nzb><file subject="test"/></nzb>';
    $first = substr($xml, 0, 25);
    $second = substr($xml, 25);

    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once()->with('alt.binaries.ftd')->andReturn([
        'count' => 2,
        'first' => 1,
        'last' => 2,
        'group' => 'alt.binaries.ftd',
    ]);
    $nntp->shouldReceive('body')
        ->once()
        ->with('first@test')
        ->andReturn(yEncArticle($first, 'first'));
    $nntp->shouldReceive('body')
        ->once()
        ->with('second@test')
        ->andReturn(yEncArticle($second, 'second'));

    $nzb = (new NzbGenerator($nntp))->fetchNzb(
        ['first@test', 'second@test'],
        'alt.binaries.ftd',
    );

    expect($nzb)->toBe($xml);
});
