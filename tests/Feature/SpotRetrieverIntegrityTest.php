<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\UsenetState;
use App\Services\AsyncSpotRetrieverService;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use App\Services\SpotBanService;
use App\Services\SpotMutationService;
use App\Services\SpotRetrieverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('old-to-new retrieval checkpoints after each completed batch', function () {
    $state = UsenetState::forNewsgroup('free.pt');
    $service = new IntegrityTestSpotRetriever;
    $checkpointObservations = [];

    $result = $service->runForTest(
        [[1, 30], [31, 60], [61, 90], [91, 100]],
        $state,
        onBatchComplete: function () use (&$checkpointObservations): void {
            $checkpointObservations[] = UsenetState::query()->where('newsgroup', 'free.pt')->value('last_article_id');
        },
    );

    expect($checkpointObservations)->toBe([30, 60, 90, 100])
        ->and($state->fresh()->last_article_id)->toBe(100)
        ->and($result['highestArticle'])->toBe(100);
});

test('interrupted old-to-new retrieval keeps its last completed checkpoint', function () {
    $state = UsenetState::query()->create([
        'newsgroup' => 'free.pt',
        'last_article_id' => 25,
        'first_article_id' => 1,
        'last_backfilled_article_id' => 0,
    ]);
    $service = new IntegrityTestSpotRetriever;
    $service->stopAfterFetch = true;

    $service->runForTest(
        [[1, 30], [31, 60]],
        $state,
    );

    expect($state->fresh()->last_article_id)->toBe(30);
});

test('forked upsert failures propagate before checkpointing', function () {
    if (! \function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the async retriever.');
    }

    $state = new UsenetState([
        'newsgroup' => 'free.pt',
        'last_article_id' => 50,
        'first_article_id' => 1,
        'last_backfilled_article_id' => 0,
    ]);
    $service = new FailingAsyncSpotRetriever;
    $service->setNntpDriver(Mockery::mock(NntpDriverInterface::class)->shouldIgnoreMissing());

    expect(fn () => $service->runForTest([[51, 60]], $state))
        ->toThrow(RuntimeException::class, 'simulated child upsert failure');

    expect($state->last_article_id)->toBe(50)
        ->and($state->exists)->toBeFalse();
});

test('personal moderation cannot delete a spot owned by another verified key', function () {
    $spot = Spot::factory()->create([
        'message_id' => 'target@spot.net',
        'poster_key_id' => 'owner-key',
        'is_verified' => true,
        'spot_posted_at' => now()->subHour(),
    ]);
    $service = new IntegrityTestSpotRetriever;

    $service->moderate([[
        '_moderation' => true,
        'command' => 'delete',
        'target_message_id' => $spot->message_id,
        'poster' => 'Attacker',
        'stamp' => now()->timestamp,
        'is_global_moderator' => false,
        'moderator_key_id' => 'different-key',
    ]]);

    $this->assertModelExists($spot);
});

test('authenticated global moderation can delete a recent spot', function () {
    $spot = Spot::factory()->create([
        'message_id' => 'target@spot.net',
        'spot_posted_at' => now()->subHour(),
    ]);
    $service = new IntegrityTestSpotRetriever;

    $service->moderate([[
        '_moderation' => true,
        'command' => 'delete',
        'target_message_id' => $spot->message_id,
        'poster' => 'Trusted moderator',
        'stamp' => now()->timestamp,
        'is_global_moderator' => true,
        'moderator_key_id' => null,
    ]]);

    $this->assertModelMissing($spot);
});

test('initial scan inserts only overview fields and ignores duplicate conflicts', function () {
    $service = new IntegrityTestSpotRetriever;
    $postedAt = now()->subDay()->startOfSecond();
    $spot = [
        'message_id' => 'initial-scan@example.test',
        'poster' => 'Poster',
        'title' => 'Initial scan title',
        'tag' => 'Tag',
        'category_code' => '01',
        'subcategories' => ['01a01'],
        'file_size' => 123,
        'spot_posted_at' => $postedAt->toDateTimeString(),
        'description' => 'Should not be written during initial scan.',
        'image_segments' => ['image-segment@example.test'],
        'nzb_segments' => ['nzb-segment@example.test'],
        'website' => 'https://example.test',
        'xml_signature' => 'signature',
        'poster_key_id' => 'poster-key',
        'is_verified' => true,
    ];

    $inserted = $service->batchUpsertForTest([$spot], initialScan: true);
    $spot['title'] = 'Updated duplicate title';
    $spot['file_size'] = 999;
    $duplicateInserted = $service->batchUpsertForTest([$spot], initialScan: true);
    $persisted = Spot::query()->where('message_id', 'initial-scan@example.test')->firstOrFail();

    expect($inserted)->toBe(1)
        ->and($duplicateInserted)->toBe(0)
        ->and($persisted->title)->toBe('Initial scan title')
        ->and($persisted->file_size)->toBe(123)
        ->and($persisted->description)->toBeNull()
        ->and($persisted->image_segments)->toBe([])
        ->and($persisted->nzb_segments)->toBe([])
        ->and($persisted->website)->toBeNull()
        ->and($persisted->xml_signature)->toBeNull()
        ->and($persisted->poster_key_id)->toBeNull()
        ->and($persisted->is_verified)->toBeFalse();
});

class IntegrityTestSpotRetriever extends SpotRetrieverService
{
    public bool $stopAfterFetch = false;

    public function __construct()
    {
        parent::__construct(
            new SpotParser,
            new NntpService(config('spotengine.nntp')),
            new SigningService,
            app(SpotMutationService::class),
            app(SpotBanService::class),
        );
    }

    /**
     * @param  array<int, array{int, int}>  $batches
     * @return array{totalProcessed: int, totalInserted: int, highestArticle: int}
     */
    public function runForTest(
        array $batches,
        UsenetState $state,
        ?callable $onBatchComplete = null,
    ): array {
        return $this->runBatches(
            $batches,
            false,
            ['count' => 100, 'first' => 1, 'last' => 100, 'group' => 'free.pt'],
            $state,
            1,
            $onBatchComplete,
        );
    }

    /** @param list<array<string, mixed>> $commands */
    public function moderate(array $commands): void
    {
        $this->processModeration($commands);
    }

    /** @param list<array<string, mixed>> $spots */
    public function batchUpsertForTest(array $spots, bool $initialScan): int
    {
        $this->initialScan = $initialScan;

        return $this->batchUpsert($spots);
    }

    #[Override]
    protected function fetchBatch(int $batchStart, int $batchEnd): array
    {
        if ($this->stopAfterFetch) {
            $this->shutdown();
        }

        return [$batchEnd - $batchStart + 1, [], [], $batchEnd];
    }
}

class FailingAsyncSpotRetriever extends AsyncSpotRetrieverService
{
    public function __construct()
    {
        parent::__construct(
            new SpotParser,
            new NntpService(config('spotengine.nntp')),
            new SigningService,
            app(SpotMutationService::class),
            app(SpotBanService::class),
        );
    }

    public function setNntpDriver(NntpDriverInterface $driver): void
    {
        $this->nntp = $driver;
    }

    /**
     * @param  array<int, array{int, int}>  $batches
     * @return array{totalProcessed: int, totalInserted: int, highestArticle: int}
     */
    public function runForTest(array $batches, UsenetState $state): array
    {
        return $this->runBatches(
            $batches,
            false,
            ['count' => 100, 'first' => 1, 'last' => 100, 'group' => 'free.pt'],
            $state,
            51,
            null,
        );
    }

    #[Override]
    protected function fetchBatch(int $batchStart, int $batchEnd): array
    {
        return [10, [['message_id' => 'child-failure@spot.net']], [], $batchEnd];
    }

    #[Override]
    protected function batchUpsert(array $spots): int
    {
        throw new RuntimeException('simulated child upsert failure');
    }
}
