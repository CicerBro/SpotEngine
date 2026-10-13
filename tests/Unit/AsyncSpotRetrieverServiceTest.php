<?php

declare(strict_types=1);

use App\Models\UsenetState;
use App\Services\AsyncSpotRetrieverService;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

final class AsyncRetrieverCleanupHarness extends AsyncSpotRetrieverService
{
    public ?Throwable $fetchFailure = null;

    public ?Throwable $upsertFailure = null;

    public ?Throwable $moderationFailure = null;

    public bool $shutdownOnSecondFetch = false;

    public array $fetchedBatches = [];

    public array $moderatedBatches = [];

    public array $checkpoints = [];

    public function __construct(NntpDriverInterface $nntp, private readonly string $childPidFile)
    {
        $this->nntp = $nntp;
    }

    public function run(UsenetState $state, ?callable $onBatchComplete = null, bool $backfill = false, array $batches = [[10, 19], [20, 29]]): array
    {
        return $this->runBatches($batches, $backfill, ['count' => 100, 'first' => 1, 'last' => 100, 'group' => 'cleanup.test'], $state, 10, $onBatchComplete);
    }

    protected function fetchBatch(int $batchStart, int $batchEnd): array
    {
        $this->fetchedBatches[] = $batchStart;

        if ($batchStart === 20) {
            if ($this->shutdownOnSecondFetch) {
                $this->shutdown();
            }

            if ($this->fetchFailure !== null) {
                throw $this->fetchFailure;
            }
        }

        return [10, [['article' => $batchStart]], [['article' => $batchStart]], $batchEnd];
    }

    protected function batchUpsert(array $spots): int
    {
        file_put_contents($this->childPidFile, getmypid() . "\n", FILE_APPEND);

        if ($this->upsertFailure !== null) {
            throw $this->upsertFailure;
        }

        return count($spots);
    }

    protected function processModeration(array $commands): void
    {
        if ($this->moderationFailure !== null) {
            throw $this->moderationFailure;
        }

        $this->moderatedBatches[] = $commands;
    }

    protected function saveState(UsenetState $state, bool $backfill, int $batchStart, int $highestArticle, int $groupFirst): void
    {
        $this->checkpoints[] = $backfill ? $batchStart : $highestArticle;
        $state->setAttribute($backfill ? 'last_backfilled_article_id' : 'last_article_id', $backfill ? $batchStart : $highestArticle);
    }
}

function makeAsyncCleanupHarness(): array
{
    if (! function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl is required for the async retriever.');
    }

    DB::shouldReceive('disconnect')->andReturnNull();
    DB::shouldReceive('reconnect')->andReturnNull();
    $driver = Mockery::mock(NntpDriverInterface::class);
    $driver->shouldReceive('detach')->andReturnNull();
    $driver->shouldReceive('quit')->andReturnNull();
    $pidFile = tempnam(sys_get_temp_dir(), 'spotengine-async-child-');

    return [new AsyncRetrieverCleanupHarness($driver, $pidFile), $pidFile];
}

function assertAsyncUpsertChildrenReaped(string $pidFile, int $expectedChildren): void
{
    $childPids = file($pidFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    unlink($pidFile);
    expect($childPids)->toHaveCount($expectedChildren);

    foreach ($childPids as $childPid) {
        $waitedPid = pcntl_waitpid((int) $childPid, $childStatus, WNOHANG);

        if ($waitedPid === 0) {
            posix_kill((int) $childPid, SIGTERM);
            pcntl_waitpid((int) $childPid, $childStatus);
        }

        expect($waitedPid)->toBe(-1);
    }
}

test('a failed next fetch collects and checkpoints the previous successful upsert', function (bool $backfill) {
    [$service, $pidFile] = makeAsyncCleanupHarness();
    $state = new UsenetState;
    $failure = new RuntimeException('Next NNTP fetch failed');
    $service->fetchFailure = $failure;
    $completedBatches = [];
    $streamCount = count(get_resources('stream'));

    try {
        $service->run($state, function (int ...$batch) use (&$completedBatches): void {
            $completedBatches[] = $batch;
        }, $backfill);
        $this->fail('Expected the next fetch to fail.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }

    expect($completedBatches)->toBe([[10, 19, 10, 1, 1]]);
    expect($service->moderatedBatches)->toBe([[['article' => 10]]]);
    expect($service->checkpoints)->toBe([$backfill ? 10 : 19]);
    expect($state->getAttribute($backfill ? 'last_backfilled_article_id' : 'last_article_id'))->toBe($backfill ? 10 : 19);
    expect(count(get_resources('stream')))->toBe($streamCount);
    assertAsyncUpsertChildrenReaped($pidFile, 1);
})->with([false, true]);

test('a failed child is reaped once without moderation or checkpoint advancement', function (array $batches, array $expectedFetchedBatches) {
    [$service, $pidFile] = makeAsyncCleanupHarness();
    $service->upsertFailure = new RuntimeException('Previous upsert failed');
    $state = new UsenetState;
    $completedBatches = [];
    $streamCount = count(get_resources('stream'));

    expect(fn () => $service->run($state, function (int ...$batch) use (&$completedBatches): void {
        $completedBatches[] = $batch;
    }, batches: $batches))->toThrow(RuntimeException::class, 'Forked spot upsert failed: RuntimeException: Previous upsert failed');

    expect($service->fetchedBatches)->toBe($expectedFetchedBatches);
    expect($service->moderatedBatches)->toBe([]);
    expect($service->checkpoints)->toBe([]);
    expect($completedBatches)->toBe([]);
    expect($state->last_article_id)->toBeNull();
    expect(count(get_resources('stream')))->toBe($streamCount);
    assertAsyncUpsertChildrenReaped($pidFile, 1);
})->with([
    'previous child' => [[[10, 19], [20, 29]], [10, 20]],
    'final child' => [[[10, 19]], [10]],
]);

test('a failed next fetch preserves its exception and reports a failed previous child', function () {
    [$service, $pidFile] = makeAsyncCleanupHarness();
    Exceptions::fake();
    $failure = new RuntimeException('Next NNTP fetch failed');
    $service->fetchFailure = $failure;
    $service->upsertFailure = new RuntimeException('Previous upsert failed');
    $state = new UsenetState;

    try {
        $service->run($state);
        $this->fail('Expected the next fetch to fail.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Forked spot upsert failed: RuntimeException: Previous upsert failed');
    expect($service->moderatedBatches)->toBe([]);
    expect($service->checkpoints)->toBe([]);
    assertAsyncUpsertChildrenReaped($pidFile, 1);
});

test('normal completion collects the final child and commits every batch once', function () {
    [$service, $pidFile] = makeAsyncCleanupHarness();
    $state = new UsenetState;
    $completedBatches = [];
    $streamCount = count(get_resources('stream'));

    $result = $service->run($state, function (int ...$batch) use (&$completedBatches): void {
        $completedBatches[] = $batch;
    });

    expect($result)->toBe(['totalProcessed' => 20, 'totalInserted' => 2, 'highestArticle' => 29]);
    expect($completedBatches)->toBe([[10, 19, 10, 1, 1], [20, 29, 10, 1, 1]]);
    expect($service->moderatedBatches)->toBe([[['article' => 10]], [['article' => 20]]]);
    expect($service->checkpoints)->toBe([19, 29]);
    expect($state->last_article_id)->toBe(29);
    expect(count(get_resources('stream')))->toBe($streamCount);
    assertAsyncUpsertChildrenReaped($pidFile, 2);
});

test('shutdown during the next fetch drains the previous child without forking another', function () {
    [$service, $pidFile] = makeAsyncCleanupHarness();
    $service->shutdownOnSecondFetch = true;
    $state = new UsenetState;

    $result = $service->run($state);

    expect($result)->toBe(['totalProcessed' => 10, 'totalInserted' => 1, 'highestArticle' => 19]);
    expect($service->moderatedBatches)->toBe([[['article' => 10]]]);
    expect($service->checkpoints)->toBe([19]);
    expect($state->last_article_id)->toBe(19);
    assertAsyncUpsertChildrenReaped($pidFile, 1);
});

test('a moderation failure after collection does not collect the child twice or advance the checkpoint', function () {
    [$service, $pidFile] = makeAsyncCleanupHarness();
    $failure = new RuntimeException('Moderation failed');
    $service->moderationFailure = $failure;
    $state = new UsenetState;
    $streamCount = count(get_resources('stream'));

    try {
        $service->run($state);
        $this->fail('Expected moderation to fail.');
    } catch (RuntimeException $exception) {
        expect($exception)->toBe($failure);
    }

    expect($service->checkpoints)->toBe([]);
    expect(count(get_resources('stream')))->toBe($streamCount);
    assertAsyncUpsertChildrenReaped($pidFile, 1);
});

test('parent drains a large child payload before waiting for its exit', function () {
    if (! \function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the async retriever.');
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
    $service = (new ReflectionClass(AsyncSpotRetrieverService::class))->newInstanceWithoutConstructor();
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
