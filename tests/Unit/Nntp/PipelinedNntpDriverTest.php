<?php

declare(strict_types=1);

use App\Services\Nntp\Contracts\NntpStreamInterface;
use App\Services\Nntp\HeadBatchOutcome;
use App\Services\Nntp\HeadBatchResult;
use App\Services\Nntp\NntpEndpoint;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpProtocol;
use App\Services\Nntp\PipelinedNntpDriver;
use App\Services\Nntp\SingleNntpDriver;

final class PipelinedTranscriptStream implements NntpStreamInterface
{
    public string $written = '';

    /** @var list<string> */
    public array $writesBeforeReads = [];

    public bool $closed = false;

    private int $offset = 0;

    public function __construct(
        private readonly string $transcript,
        private readonly int $readChunkSize = 8192,
        private readonly bool $timedOut = false,
    ) {}

    public function read(int $length): string|false
    {
        $this->writesBeforeReads[] = $this->written;

        if ($this->offset >= strlen($this->transcript)) {
            return false;
        }

        $chunk = substr($this->transcript, $this->offset, min($length, $this->readChunkSize));
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function write(string $data): int|false
    {
        $this->written .= $data;

        return strlen($data);
    }

    public function metadata(): array
    {
        return ['timed_out' => $this->timedOut];
    }

    public function eof(): bool
    {
        return $this->offset >= strlen($this->transcript);
    }

    public function close(): void
    {
        $this->closed = true;
    }
}

/** @return array{PipelinedNntpDriver, PipelinedTranscriptStream} */
function pipelinedDriverTranscript(string $transcript, int $depth = 2, int $chunkSize = 8192, bool $compressed = false, bool $timedOut = false): array
{
    $driver = new PipelinedNntpDriver(new NntpEndpoint('localhost', 119, false), $depth);
    $stream = new PipelinedTranscriptStream($transcript, $chunkSize, $timedOut);
    (new ReflectionProperty(SingleNntpDriver::class, 'socket'))->setValue($driver, fopen('php://temp', 'w+'));
    (new ReflectionProperty(SingleNntpDriver::class, 'protocol'))->setValue($driver, new NntpProtocol($stream));
    (new ReflectionProperty(SingleNntpDriver::class, 'compressionEnabled'))->setValue($driver, $compressed);

    return [$driver, $stream];
}

test('queues the configured number of HEAD requests before reading and refills as replies complete', function (int $depth, string $firstCommands) {
    [$driver, $stream] = pipelinedDriverTranscript(
        "221 headers\r\nX-XML: first\r\n.\r\n221 headers\r\nX-XML: second\r\n.\r\n221 headers\r\nX-XML: third\r\n.\r\n",
        depth: $depth,
    );
    $observedWrites = [];
    $outcomes = [];

    try {
        $results = $driver->headBatch(['one@test', 'two@test', 'three@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result) use ($stream, &$observedWrites, &$outcomes): void {
            $observedWrites[] = $stream->written;
            $outcomes[$id] = $result->headers;
        });

        expect($stream->writesBeforeReads[0])->toBe($firstCommands);
        expect($observedWrites[0])->toBe($firstCommands);
        expect($observedWrites[1])->toBe($depth === 1 ? "HEAD <one@test>\r\nHEAD <two@test>\r\n" : "HEAD <one@test>\r\nHEAD <two@test>\r\nHEAD <three@test>\r\n");
        expect($outcomes)->toBe([
            'one@test' => ['x-xml' => 'first'],
            'two@test' => ['x-xml' => 'second'],
            'three@test' => ['x-xml' => 'third'],
        ]);
        expect($results)->toBe([]);
    } finally {
        $driver->detach();
    }
})->with([
    'depth one' => [1, "HEAD <one@test>\r\n"],
    'depth two' => [2, "HEAD <one@test>\r\nHEAD <two@test>\r\n"],
]);

test('preserves response alignment across missing message IDs and article numbers', function (bool $streamResults) {
    [$driver, $stream] = pipelinedDriverTranscript(
        "221 headers\r\nSubject: First\r\n.\r\n430 missing\r\n423 missing number\r\n221 headers\r\nSubject: Last\r\n.\r\n",
        depth: 4,
        chunkSize: 3,
    );
    $outcomes = [];
    $callback = $streamResults ? function (int|string $id, HeadBatchResult $result) use (&$outcomes): void {
        $outcomes[$id] = $result->outcome;
    } : null;

    try {
        $results = $driver->headBatch(['first@test', 'missing@test', 42, 'last@test'], showProgress: false, onArticle: $callback);

        expect($stream->written)->toBe("HEAD <first@test>\r\nHEAD <missing@test>\r\nHEAD 42\r\nHEAD <last@test>\r\n");

        if ($streamResults) {
            expect($outcomes)->toBe([
                'first@test' => HeadBatchOutcome::Success,
                'missing@test' => HeadBatchOutcome::Missing,
                42 => HeadBatchOutcome::Missing,
                'last@test' => HeadBatchOutcome::Success,
            ]);
            expect($results)->toBe([]);
        } else {
            expect($results)->toBe([
                'first@test' => ['subject' => 'First'],
                'missing@test' => null,
                42 => null,
                'last@test' => ['subject' => 'Last'],
            ]);
        }
    } finally {
        $driver->detach();
    }
})->with([true, false]);

test('keeps coalesced compressed and plain replies separate', function (int $chunkSize) {
    [$driver] = pipelinedDriverTranscript(
        "221 COMPRESS=GZIP\r\n" . gzcompress("X-XML: first\r\n") . ".\r\n" .
        "221 headers\r\nX-XML: second\r\n.\r\n" .
        "221 COMPRESS=GZIP\r\n" . gzcompress("X-XML: third\r\n") . ".\r\n",
        depth: 3,
        chunkSize: $chunkSize,
        compressed: true,
    );

    try {
        $results = $driver->headBatch(['first@test', 'second@test', 'third@test'], showProgress: false);

        expect($results)->toBe([
            'first@test' => ['x-xml' => 'first'],
            'second@test' => ['x-xml' => 'second'],
            'third@test' => ['x-xml' => 'third'],
        ]);
    } finally {
        $driver->detach();
    }
})->with([3, 8192]);

test('bounds queued command bytes even with a large pipeline depth', function () {
    $articles = array_map(fn (int $id): string => $id . str_repeat('x', 480) . '@test', range(1, 10));
    [$driver, $stream] = pipelinedDriverTranscript(str_repeat("221 headers\r\nSubject: Available\r\n.\r\n", 10), depth: 200);

    try {
        $results = $driver->headBatch($articles, showProgress: false);

        expect(strlen($stream->writesBeforeReads[0]))->toBeLessThanOrEqual(2048);
        expect(substr_count($stream->writesBeforeReads[0], 'HEAD '))->toBe(3);
        expect($results)->toHaveCount(10);
    } finally {
        $driver->detach();
    }
});

test('closes the connection on timeout or premature EOF with outstanding requests', function (bool $timedOut, string $message) {
    [$driver] = pipelinedDriverTranscript('', timedOut: $timedOut);

    expect(fn () => $driver->headBatch(['first@test', 'second@test'], showProgress: false))
        ->toThrow(NntpException::class, $message);
    expect($driver->isConnected())->toBeFalse();
})->with([
    'timeout' => [true, 'timed out'],
    'EOF' => [false, 'closed'],
]);

test('closes the connection when queued replies cannot safely continue', function (string $transcript, string $message) {
    [$driver] = pipelinedDriverTranscript($transcript, compressed: true);

    expect(fn () => $driver->headBatch(['first@test', 'second@test'], showProgress: false))
        ->toThrow(NntpException::class, $message);
    expect($driver->isConnected())->toBeFalse();
})->with([
    'server error' => ["400 service unavailable\r\n", 'HEAD failed'],
    'wrong missing response for a message ID' => ["423 no such article number\r\n", 'HEAD failed'],
    'wrong article' => ["221 headers\r\nMessage-ID: <different@test>\r\n.\r\n", 'does not match'],
    'corrupt compression' => ["221 COMPRESS=GZIP\r\nnot-compressed.\r\n", 'decompress'],
]);

test('rejects injected commands without sending them and closes pending requests', function () {
    [$driver, $stream] = pipelinedDriverTranscript('');

    expect(fn () => $driver->headBatch(['safe@test', "evil@test\r\nQUIT"], showProgress: false))
        ->toThrow(NntpException::class, 'line break');
    expect($stream->written)->toBe("HEAD <safe@test>\r\n");
    expect($driver->isConnected())->toBeFalse();
});

test('enforces header payload limits for plain and compressed replies', function (bool $compressed) {
    config()->set('spotengine.payload_limits.nntp_header_bytes', 128);
    $headers = 'X-XML: ' . str_repeat('x', 4096) . "\r\n";
    $transcript = $compressed
        ? "221 COMPRESS=GZIP\r\n" . gzcompress($headers) . ".\r\n"
        : "221 headers\r\n" . $headers . ".\r\n";
    [$driver] = pipelinedDriverTranscript($transcript, compressed: $compressed);

    expect(fn () => $driver->headBatch(['first@test', 'second@test'], showProgress: false))
        ->toThrow(NntpException::class);
    expect($driver->isConnected())->toBeFalse();
})->with([true, false]);

test('closes pending replies when a streaming callback fails', function () {
    [$driver] = pipelinedDriverTranscript(str_repeat("221 headers\r\nSubject: Available\r\n.\r\n", 2));

    expect(fn () => $driver->headBatch(['first@test', 'second@test'], showProgress: false, onArticle: function (int|string $id, HeadBatchResult $result): void {
        throw new RuntimeException('consumer failed');
    }))->toThrow(RuntimeException::class, 'consumer failed');
    expect($driver->isConnected())->toBeFalse();
});

test('reports progress and leaves an empty batch silent', function () {
    [$driver] = pipelinedDriverTranscript("221 headers\r\nSubject: Available\r\n.\r\n");
    ob_start();

    try {
        $empty = $driver->headBatch([]);
        $emptyOutput = ob_get_contents();
        $driver->headBatch(['first@test']);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
        $driver->detach();
    }

    expect($empty)->toBe([]);
    expect($emptyOutput)->toBe('');
    expect($output)->toContain('Progress: 0/1 (0%)', 'Progress: 1/1 (100%)')->toEndWith("\n");
});

test('rejects a nonpositive pipeline depth', function () {
    new PipelinedNntpDriver(new NntpEndpoint('localhost', 119, false), 0);
})->throws(InvalidArgumentException::class, 'at least one');

test('inherits overview and body retrieval on the same connection', function () {
    [$driver, $stream] = pipelinedDriverTranscript(
        "224 overview follows\r\n42\tTitle\tPoster\tDate\t<spot@test>\t\t0\t0\r\n.\r\n" .
        "222 body follows\r\nfirst\r\n..second\r\n.\r\n",
    );

    try {
        $overview = $driver->xover(42, 42);
        $body = $driver->body('body@test');

        expect($overview)->toBe([42 => ['subject' => 'Title', 'from' => 'Poster', 'date' => 'Date', 'message_id' => 'spot@test']]);
        expect($body)->toBe("first\r\n.second");
        expect($stream->written)->toBe("XOVER 42-42\r\nBODY <body@test>\r\n");
    } finally {
        $driver->detach();
    }
});
