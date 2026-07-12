<?php

declare(strict_types=1);

use App\Services\Nntp\Contracts\NntpStreamInterface;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpProtocol;
use App\Services\Nntp\ResponseCode;

final class ProtocolTranscriptStream implements NntpStreamInterface
{
    public string $written = '';

    private int $offset = 0;

    public function __construct(
        private readonly string $transcript,
        private readonly int $readChunkSize = 8192,
        private readonly int $writeChunkSize = 8192,
        private readonly bool $timedOut = false,
    ) {}

    public function read(int $length): string|false
    {
        if ($this->offset >= strlen($this->transcript)) {
            return false;
        }

        $chunk = substr($this->transcript, $this->offset, min($length, $this->readChunkSize));
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function write(string $data): int|false
    {
        $chunk = substr($data, 0, $this->writeChunkSize);
        $this->written .= $chunk;

        return strlen($chunk);
    }

    public function metadata(): array
    {
        return ['timed_out' => $this->timedOut];
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->transcript);
    }

    public function close(): void {}
}

test('frames status continuations and partial reads', function () {
    $stream = new ProtocolTranscriptStream(
        "200-first greeting line\r\n200 server ready\r\n",
        readChunkSize: 2,
    );

    $response = (new NntpProtocol($stream))->readResponse();

    expect($response->code)->toBe(ResponseCode::ReadyPostingAllowed)
        ->and($response->statusText)->toBe('server ready');
});

test('writes commands completely across partial writes', function () {
    $stream = new ProtocolTranscriptStream('', writeChunkSize: 3);

    (new NntpProtocol($stream))->writeCommand('GROUP free.pt');

    expect($stream->written)->toBe("GROUP free.pt\r\n");
});

test('rejects injected or overlong command framing', function (string $command) {
    (new NntpProtocol(new ProtocolTranscriptStream('')))->writeCommand($command);
})->with([
    'carriage return injection' => "GROUP free.pt\rQUIT",
    'line feed injection' => "GROUP free.pt\nQUIT",
    'longer than RFC command limit' => str_repeat('a', 511),
])->throws(NntpException::class);

test('terminates text responses and un-stuffs leading dots', function () {
    $stream = new ProtocolTranscriptStream("first\r\n..second\r\n.\r\nignored\r\n", readChunkSize: 3);

    expect((new NntpProtocol($stream))->readTextResponse())->toBe(['first', '.second']);
});

test('preserves transport line boundaries in body responses', function () {
    $stream = new ProtocolTranscriptStream("=ybegin line=128 size=3 name=x\r\nklm\r\n=yend size=3\r\n.\r\n");

    expect((new NntpProtocol($stream))->readBodyResponse())
        ->toBe("=ybegin line=128 size=3 name=x\r\nklm\r\n=yend size=3");
});

test('raises structured exceptions for timeouts and premature EOF', function (ProtocolTranscriptStream $stream, string $message) {
    (new NntpProtocol($stream))->readTextResponse();
})->with([
    'timeout' => [new ProtocolTranscriptStream('', timedOut: true), 'timed out'],
    'EOF' => [new ProtocolTranscriptStream('unterminated'), 'closed'],
])->throws(NntpException::class);

test('rejects corrupt compressed overview responses', function () {
    NntpProtocol::decodeCompressedTextResponse("not-compressed.\r\n");
})->throws(NntpException::class, 'decompress');

test('decompresses framed overview responses', function () {
    $compressed = gzcompress("1\tSubject\tPoster\tDate\t<one@test>\r\n");
    $stream = new ProtocolTranscriptStream($compressed . ".\r\n", readChunkSize: 3);

    expect((new NntpProtocol($stream))->readCompressedTextResponse())->toBe([
        "1\tSubject\tPoster\tDate\t<one@test>",
    ]);
});
