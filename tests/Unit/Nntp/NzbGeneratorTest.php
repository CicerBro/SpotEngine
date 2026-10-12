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
            $encoded .= '=' . chr(($encodedByte + 64) % 256);
        } else {
            $encoded .= chr($encodedByte);
        }
    }

    return '=ybegin line=128 size=' . strlen($data) . " name={$name}\r\n"
        . $encoded . "\r\n"
        . '=yend size=' . strlen($data);
}

function specialZipArticle(string $data): string
{
    $compressed = gzdeflate($data);

    if ($compressed === false) {
        throw new RuntimeException('Unable to create compressed NZB test fixture.');
    }

    $encoded = str_replace(
        ['=', "\n", "\r", "\0"],
        ['=D', '=C', '=B', '=A'],
        $compressed,
    );

    return implode("\r\n", str_split($encoded, 32));
}

test('fetchNzb removes NNTP transport line breaks from Spotnet compressed data', function () {
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<nzb><file subject=\"test\"/></nzb>";

    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once()->with('alt.binaries.ftd')->andReturn([
        'count' => 1,
        'first' => 1,
        'last' => 1,
        'group' => 'alt.binaries.ftd',
    ]);
    $nntp->shouldReceive('body')
        ->once()
        ->with('test@test')
        ->andReturn(specialZipArticle($xml));

    $nzb = (new NzbGenerator($nntp))->fetchNzb(
        ['test@test'],
        'alt.binaries.ftd',
    );

    expect($nzb)->toBe($xml);
});

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

test('fetchNzb tolerates an incomplete trailing yEnc escape', function () {
    $xml = '<?xml version="1.0"?><nzb><file subject="test"/></nzb>';
    $body = preg_replace('/\r\n=yend/', "=\r\n=yend", yEncArticle($xml, 'test'), 1);

    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once()->with('alt.binaries.ftd')->andReturn([
        'count' => 1,
        'first' => 1,
        'last' => 1,
        'group' => 'alt.binaries.ftd',
    ]);
    $nntp->shouldReceive('body')
        ->once()
        ->with('test@test')
        ->andReturn((string) $body);

    $nzb = (new NzbGenerator($nntp))->fetchNzb(
        ['test@test'],
        'alt.binaries.ftd',
    );

    expect($nzb)->toBe($xml);
});

test('rejects excessive NZB segment counts before opening the group', function () {
    config()->set('spotengine.payload_limits.nzb_segments', 1);
    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldNotReceive('group');
    $nntp->shouldNotReceive('body');

    expect(fn () => (new NzbGenerator($nntp))->fetchNzb(['first@test', 'second@test'], 'free.pt'))
        ->toThrow(RuntimeException::class, 'segment count');
});

test('stops fetching NZB segments when cumulative input exceeds the limit', function (string $limit, int $maximum, string $body) {
    config()->set('spotengine.payload_limits.' . $limit, $maximum);
    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once()->with('free.pt');
    $nntp->shouldReceive('body')->once()->with('first@test')->andReturn($body);
    $nntp->shouldReceive('body')->once()->with('second@test')->andReturn($body);
    $nntp->shouldNotReceive('body')->with('third@test');

    expect(fn () => (new NzbGenerator($nntp))->fetchNzb(['first@test', 'second@test', 'third@test'], 'free.pt'))
        ->toThrow(RuntimeException::class, 'input exceeds');
})->with([
    'bytes' => ['nzb_input_bytes', 7, 'abcd'],
    'lines' => ['nntp_response_lines', 3, "a\r\nb"],
]);

test('rejects compressed NZBs larger than the input limit', function () {
    config()->set('spotengine.payload_limits.nzb_compressed_bytes', 1);
    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once();
    $nntp->shouldReceive('body')->once()->andReturn(specialZipArticle('<nzb><file/></nzb>'));

    expect(fn () => (new NzbGenerator($nntp))->fetchNzb(['test@test'], 'free.pt'))
        ->toThrow(RuntimeException::class, 'Compressed NZB');
});

test('bounds every supported decompression format', function (string $format) {
    config()->set('spotengine.payload_limits.nzb_output_bytes', 128);
    $xml = '<nzb>' . str_repeat('x', 4096) . '</nzb>';
    $compressed = match ($format) {
        'gzip' => gzencode($xml),
        'zlib' => gzcompress($xml),
        'offset' => 'custom' . gzdeflate($xml),
        'base64' => base64_encode(gzdeflate($xml)),
        default => gzdeflate($xml),
    };
    $body = str_replace(['=', "\n", "\r", "\0"], ['=D', '=C', '=B', '=A'], $compressed);
    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once();
    $nntp->shouldReceive('body')->once()->andReturn($body);

    expect(fn () => (new NzbGenerator($nntp))->fetchNzb(['test@test'], 'free.pt'))
        ->toThrow(RuntimeException::class);
})->with(['deflate', 'gzip', 'zlib', 'offset', 'base64']);

test('accepts XML exactly at the output limit and rejects larger XML', function (int $limit, bool $valid) {
    config()->set('spotengine.payload_limits.nzb_output_bytes', $limit);
    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once();
    $nntp->shouldReceive('body')->once()->andReturn('<nzb></nzb>');
    $fetch = fn () => (new NzbGenerator($nntp))->fetchNzb(['test@test'], 'free.pt');

    if ($valid) {
        expect($fetch())->toBe('<nzb></nzb>');
    } else {
        expect($fetch)->toThrow(RuntimeException::class, 'Decoded NZB');
    }
})->with([[11, true], [10, false]]);

test('rejects bzip2 payloads instead of allocating unbounded output', function () {
    $nntp = Mockery::mock(SingleNntpDriver::class);
    $nntp->shouldReceive('group')->once();
    $nntp->shouldReceive('body')->once()->andReturn('BZh9untrusted');

    expect(fn () => (new NzbGenerator($nntp))->fetchNzb(['test@test'], 'free.pt'))
        ->toThrow(RuntimeException::class, 'bounded output');
});
