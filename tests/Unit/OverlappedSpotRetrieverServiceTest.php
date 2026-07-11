<?php

declare(strict_types=1);

use App\Services\OverlappedSpotRetrieverService;

test('parent drains a large child payload before waiting for its exit', function () {
    if (! \function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the overlapped retriever.');
    }

    [$readPipe, $writePipe] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $childPid = pcntl_fork();

    if ($childPid === 0) {
        fclose($readPipe);
        $payload = json_encode([
            'ok' => false,
            'error' => 'large child error: ' . str_repeat('x', 262_144),
        ], JSON_THROW_ON_ERROR);
        $offset = 0;

        while ($offset < strlen($payload)) {
            $written = fwrite($writePipe, substr($payload, $offset));

            if ($written === false || $written === 0) {
                break;
            }

            $offset += $written;
        }

        fclose($writePipe);
        exit(1);
    }

    fclose($writePipe);
    $service = (new ReflectionClass(OverlappedSpotRetrieverService::class))->newInstanceWithoutConstructor();
    $awaitChild = new ReflectionMethod($service, 'awaitUpsertChild');
    $startedAt = microtime(true);

    try {
        $awaitChild->invoke($service, $childPid, $readPipe);
        $this->fail('Expected the simulated child to fail.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('large child error');
    }

    expect(microtime(true) - $startedAt)->toBeLessThan(5.0);
});
