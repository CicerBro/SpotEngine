<?php

declare(strict_types=1);

use App\Services\Nntp\Contracts\NntpStreamInterface;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpProtocol;
use App\Services\Nntp\ParallelNntpDriver;
use App\Services\Nntp\ResponseCode;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\Nntp\SpotnetHeaderParser;

final class TranscriptNntpStream implements NntpStreamInterface
{
    private int $offset = 0;

    public string $written = '';

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

function injectSingleSocket(SingleNntpDriver $driver, mixed $socket): void
{
    $property = new ReflectionProperty($driver, 'socket');
    $property->setValue($driver, $socket);
}

function injectParallelSockets(ParallelNntpDriver $driver, array $sockets): void
{
    $property = new ReflectionProperty($driver, 'sockets');
    $property->setValue($driver, $sockets);
}

test('status response continuations and partial reads are framed correctly', function () {
    $stream = new TranscriptNntpStream(
        "200-first greeting line\r\n200 server ready\r\n",
        readChunkSize: 2,
    );

    $response = (new NntpProtocol($stream))->readResponse();

    expect($response->code)->toBe(ResponseCode::ReadyPostingAllowed)
        ->and($response->statusText)->toBe('server ready');
});

test('commands are fully written when the stream accepts partial writes', function () {
    $stream = new TranscriptNntpStream('', writeChunkSize: 3);
    $protocol = new NntpProtocol($stream);

    $protocol->writeCommand('GROUP free.pt');

    expect($stream->written)->toBe("GROUP free.pt\r\n");
});

test('commands reject line injection and overlong framing', function (string $command) {
    $protocol = new NntpProtocol(new TranscriptNntpStream(''));

    $protocol->writeCommand($command);
})->with([
    'carriage return injection' => "GROUP free.pt\rQUIT",
    'line feed injection' => "GROUP free.pt\nQUIT",
    'longer than RFC command limit' => str_repeat('a', 511),
])->throws(NntpException::class);

test('text responses terminate on dot and unstuff leading dots', function () {
    $stream = new TranscriptNntpStream("first\r\n..second\r\n.\r\nignored\r\n", readChunkSize: 3);

    $lines = (new NntpProtocol($stream))->readTextResponse();

    expect($lines)->toBe(['first', '.second']);
});

test('body responses preserve transport line boundaries for yEnc', function () {
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, "222 1 <segment@test> body follows\r\n=ybegin line=128 size=3 name=x\r\nklm\r\n=yend size=3\r\n.\r\n");

    $driver = new SingleNntpDriver('localhost', ssl: false);
    injectSingleSocket($driver, $client);

    expect($driver->body('segment@test'))->toBe("=ybegin line=128 size=3 name=x\r\nklm\r\n=yend size=3");

    $driver->detach();
    fclose($server);
});

test('timeouts and premature EOF throw structured protocol exceptions', function (TranscriptNntpStream $stream, string $message) {
    $protocol = new NntpProtocol($stream);

    try {
        $protocol->readTextResponse();
    } catch (NntpException $exception) {
        expect($exception->getMessage())->toContain($message);

        return;
    }

    $this->fail('Expected an NNTP protocol exception.');
})->with([
    'timeout' => [new TranscriptNntpStream('', timedOut: true), 'timed out'],
    'EOF' => [new TranscriptNntpStream('unterminated'), 'closed'],
]);

test('compressed XOVER corruption is a hard failure', function () {
    NntpProtocol::decodeCompressedTextResponse("not-compressed.\r\n");
})->throws(NntpException::class, 'decompress');

test('compressed XOVER responses are decompressed after complete framing', function () {
    $compressed = gzcompress("1\tSubject\tPoster\tDate\t<one@test>\r\n");
    $stream = new TranscriptNntpStream($compressed.".\r\n", readChunkSize: 3);

    expect((new NntpProtocol($stream))->readCompressedTextResponse())->toBe([
        "1\tSubject\tPoster\tDate\t<one@test>",
    ]);
});

test('Spotnet headers preserve repeated fields and folded continuations', function () {
    $parser = new SpotnetHeaderParser;

    $headers = $parser->parse([
        'Subject: ignored',
        'X-XML: <Spotnet>',
        'X-XML: <Title>Test</Title>',
        "\t</Spotnet>",
        'From: =?UTF-8?Q?Jos=C3=A9?= <poster@test>',
    ], ['x-xml' => true, 'from' => true]);

    expect($headers['x-xml'])->toBe('<Spotnet><Title>Test</Title></Spotnet>')
        ->and($headers['from'])->toContain('José');
});

test('single HEAD parsing preserves repeated Spotnet headers', function () {
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, "221 1 <spot@test> headers follow\r\nX-XML: <Spotnet>\r\nX-XML: <Title>Test</Title>\r\n </Spotnet>\r\n.\r\n");

    $driver = new SingleNntpDriver('localhost', ssl: false);
    injectSingleSocket($driver, $client);

    expect($driver->head('spot@test')['x-xml'])->toBe('<Spotnet><Title>Test</Title></Spotnet>');

    $driver->detach();
    fclose($server);
});

test('parallel group selection drops stale backends and keeps the common range', function () {
    $driver = new ParallelNntpDriver([
        'host' => 'localhost',
        'port' => 119,
        'ssl' => false,
        'timeout' => 1,
    ], 3);

    $clients = [];
    $servers = [];

    foreach ([1000, 950, 700] as $last) {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, "211 900 1 {$last} free.pt\r\n");
        $clients[] = $client;
        $servers[] = $server;
    }

    injectParallelSockets($driver, $clients);
    $summary = $driver->group('free.pt');

    expect($summary['last'])->toBe(950)
        ->and($driver->getConnectionCount())->toBe(2);

    $driver->detach();
    foreach ($servers as $server) {
        fclose($server);
    }
});

test('parallel HEAD uses the shared Spotnet parser and wanted-header filter', function () {
    $driver = new ParallelNntpDriver([
        'host' => 'localhost',
        'port' => 119,
        'ssl' => false,
        'timeout' => 1,
    ]);
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, "221 1 <spot@test> headers follow\r\nSubject: ignored\r\nX-XML: <Spotnet>\r\nX-XML: <Title>Parallel</Title>\r\n </Spotnet>\r\n.\r\n");
    injectParallelSockets($driver, [$client]);

    $headers = $driver->headParallel([1], showProgress: false);

    expect($headers[1])->toBe([
        'x-xml' => '<Spotnet><Title>Parallel</Title></Spotnet>',
    ]);

    $driver->detach();
    fclose($server);
});

test('parallel XOVER rejects incomplete socket responses', function () {
    $driver = new ParallelNntpDriver([
        'host' => 'localhost',
        'port' => 119,
        'ssl' => false,
        'timeout' => 0,
    ]);
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, "224 overview follows\r\n1\tSubject\tPoster\tDate\t<one@test>\r\n");
    injectParallelSockets($driver, [$client]);

    try {
        $driver->xover(1, 1);
    } finally {
        $driver->detach();
        fclose($server);
    }
})->throws(NntpException::class, 'incomplete');

test('replacement connections authenticate and reselect the active group', function () {
    $server = null;
    $driver = new ParallelNntpDriver([
        'host' => 'localhost',
        'port' => 119,
        'ssl' => false,
        'username' => 'user',
        'password' => 'secret',
        'timeout' => 1,
    ], connector: function () use (&$server) {
        [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($server, "200 ready\r\n381 password required\r\n281 authenticated\r\n211 10 1 10 free.pt\r\n");

        return $client;
    });

    $groupProperty = new ReflectionProperty($driver, 'currentGroup');
    $groupProperty->setValue($driver, 'free.pt');
    $method = new ReflectionMethod($driver, 'reconnectOne');
    $socket = $method->invoke($driver);
    stream_set_blocking($server, false);

    expect($socket)->not->toBeNull()
        ->and(fread($server, 4096))->toBe(
            "AUTHINFO USER user\r\nAUTHINFO PASS secret\r\nGROUP free.pt\r\n",
        );

    fclose($socket);
    fclose($server);
});

test('detach closes inherited descriptors without sending QUIT', function () {
    $driver = new ParallelNntpDriver([
        'host' => 'localhost',
        'port' => 119,
        'ssl' => false,
        'timeout' => 1,
    ]);
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    injectParallelSockets($driver, [$client]);
    stream_set_blocking($server, false);

    $driver->detach();

    expect($driver->getConnectionCount())->toBe(0)
        ->and(fread($server, 1024))->toBe('');

    fclose($server);
});
