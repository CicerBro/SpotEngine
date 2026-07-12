<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\HeadBatchResult;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mockDriver = Mockery::mock(NntpDriverInterface::class);
    $this->mockDriver->shouldReceive('connect');
    $this->mockDriver->shouldReceive('group')->andReturn([
        'count' => 1,
        'first' => 1,
        'last' => 1,
        'group' => 'free.pt',
    ]);
    $this->mockDriver->shouldReceive('quit');

    $mockNntpService = Mockery::mock(NntpService::class);
    $mockNntpService->shouldReceive('getConfig')->andReturn([
        'connections' => 1,
        'groups' => ['spots' => 'free.pt'],
    ]);
    $mockNntpService->shouldReceive('makeDriver')->andReturn($this->mockDriver);

    $this->app->instance(NntpService::class, $mockNntpService);
});

/**
 * @param  array<int|string, HeadBatchResult|array<string, string>|null>  $results
 */
function simulateStreamingHeadBatch(NntpDriverInterface $mockDriver, array $results): void
{
    $mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturnUsing(function (array $messageIds, bool $showProgress, ?callable $onArticle) use ($results): array {
            foreach ($messageIds as $messageId) {
                $result = $results[$messageId] ?? null;

                $onArticle(
                    $messageId,
                    $result instanceof HeadBatchResult
                        ? $result
                        : ($result === null ? HeadBatchResult::missing() : HeadBatchResult::success($result)),
                );
            }

            return [];
        });
}

test('enrich preserves title when upserting spot data', function () {
    $spot = Spot::factory()->create([
        'title' => 'My Test Title',
        'xml_signature' => null,
        'description' => null,
    ]);

    $xml = <<<'XML'
    <Spotnet><Posting>
        <Title>My Test Title</Title>
        <Description>Enriched description</Description>
        <Category>01</Category>
        <Website>https://example.com</Website>
        <Image><Segment>img-segment@news</Segment></Image>
        <NZB><Segment>nzb-segment@news</Segment></NZB>
    </Posting></Spotnet>
    XML;

    simulateStreamingHeadBatch($this->mockDriver, [
        $spot->message_id => [
            'x-xml' => $xml,
            'x-xml-signature' => 'sig123',
            'x-user-key' => '',
            'message-id' => $spot->message_id,
        ],
    ]);

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    $spot->refresh();

    expect($spot->title)->toBe('My Test Title')
        ->and($spot->description)->toBe('Enriched description')
        ->and($spot->xml_signature)->toBe('sig123')
        ->and($spot->image_segments)->toBe(['img-segment@news'])
        ->and($spot->nzb_segments)->toBe(['nzb-segment@news']);
});

test('enrich reports all spots enriched when none are unenriched', function () {
    $this->artisan('spot:enrich')
        ->assertSuccessful()
        ->expectsOutputToContain('All spots are already enriched');
});

test('enrich processes spots in descending primary-key order', function () {
    $lowerIdSpot = Spot::factory()->create([
        'xml_signature' => null,
        'spot_posted_at' => now()->subDay(),
    ]);
    $higherIdSpot = Spot::factory()->create([
        'xml_signature' => null,
        'spot_posted_at' => now(),
    ]);

    simulateStreamingHeadBatch($this->mockDriver, [
        $higherIdSpot->message_id => null,
    ]);

    $this->artisan('spot:enrich --limit=1')
        ->assertSuccessful();

    expect(Spot::find($higherIdSpot->id))->toBeNull()
        ->and($lowerIdSpot->fresh()->xml_signature)->toBeNull();
});

test('enrich warns that very old spots can take a long time for NNTP replies', function () {
    Spot::factory()->create(['xml_signature' => null]);

    simulateStreamingHeadBatch($this->mockDriver, [
        Spot::query()->value('message_id') => null,
    ]);

    $this->artisan('spot:enrich')
        ->assertSuccessful()
        ->expectsOutputToContain('Very old spots can take a long time for an NNTP HEAD reply');
});

test('enrich advances through batches using a primary-key cursor', function () {
    $firstSpot = Spot::factory()->create([
        'xml_signature' => null,
        'spot_posted_at' => now()->subMinutes(2),
    ]);
    $secondSpot = Spot::factory()->create([
        'xml_signature' => null,
        'spot_posted_at' => now()->subMinute(),
    ]);
    $thirdSpot = Spot::factory()->create([
        'xml_signature' => null,
        'spot_posted_at' => now(),
    ]);

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->with(
            [$thirdSpot->message_id, $secondSpot->message_id, $firstSpot->message_id],
            false,
            Mockery::type('callable'),
        )
        ->andReturnUsing(function (array $messageIds, bool $showProgress, ?callable $onArticle): array {
            foreach ($messageIds as $messageId) {
                $onArticle($messageId, HeadBatchResult::missing());
            }

            return [];
        });

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('spot:enrich --batch=2')
        ->assertSuccessful();

    $batchQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'from "spots"')
            && str_contains($query, '"xml_signature" is null')
            && str_contains($query, 'limit 2'))
        ->values();

    DB::disableQueryLog();

    expect($batchQueries)->toHaveCount(2)
        ->and($batchQueries->last())->toContain('"id" < ?');
});

test('enrich deletes spots whose HEAD is definitively missing', function () {
    $spot = Spot::factory()->create([
        'title' => 'Keep This Title',
        'xml_signature' => null,
    ]);

    simulateStreamingHeadBatch($this->mockDriver, [
        $spot->message_id => null,
    ]);

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    expect(Spot::find($spot->id))->toBeNull();
});

test('enrich handles mixed missing and successful HEAD results in the same batch', function () {
    $failedSpot = Spot::factory()->create([
        'title' => 'Failed Spot',
        'xml_signature' => null,
        'description' => null,
    ]);

    $successSpot = Spot::factory()->create([
        'title' => 'Success Spot',
        'xml_signature' => null,
        'description' => null,
    ]);

    $xml = <<<'XML'
    <Spotnet><Posting>
        <Title>Success Spot</Title>
        <Description>Enriched</Description>
        <Category>01</Category>
        <Website>https://example.com</Website>
        <Image><Segment>img@news</Segment></Image>
        <NZB><Segment>nzb@news</Segment></NZB>
    </Posting></Spotnet>
    XML;

    simulateStreamingHeadBatch($this->mockDriver, [
        $failedSpot->message_id => null,
        $successSpot->message_id => [
            'x-xml' => $xml,
            'x-xml-signature' => 'sig456',
            'x-user-key' => '',
            'message-id' => $successSpot->message_id,
        ],
    ]);

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    $successSpot->refresh();

    expect(Spot::find($failedSpot->id))->toBeNull()
        ->and($successSpot->xml_signature)->toBe('sig456')
        ->and($successSpot->description)->toBe('Enriched');
});

test('enrich deletes spots with no NZB segments', function () {
    $spot = Spot::factory()->create([
        'xml_signature' => null,
    ]);

    $xml = <<<'XML'
    <Spotnet><Posting>
        <Title>No NZB</Title>
        <Description>A spot without NZB</Description>
        <Category>01</Category>
    </Posting></Spotnet>
    XML;

    simulateStreamingHeadBatch($this->mockDriver, [
        $spot->message_id => [
            'x-xml' => $xml,
            'x-xml-signature' => 'sig',
            'x-user-key' => '',
            'message-id' => $spot->message_id,
        ],
    ]);

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    expect(Spot::find($spot->id))->toBeNull();
});

test('enrich streams large batches through a single headBatch call', function () {
    $spots = Spot::factory()->count(3)->create([
        'xml_signature' => null,
    ])->sortBy('id')->values();

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->with($spots->pluck('message_id')->all(), false, Mockery::type('callable'))
        ->andReturnUsing(function (array $messageIds, bool $showProgress, ?callable $onArticle): array {
            foreach ($messageIds as $messageId) {
                $onArticle($messageId, HeadBatchResult::missing());
            }

            return [];
        });

    $this->artisan('spot:enrich --batch=1')
        ->assertSuccessful();

    foreach ($spots as $spot) {
        expect(Spot::find($spot->id))->toBeNull();
    }
});

test('enrich aborts and preserves spots when HEAD reports a systemic failure', function () {
    $spots = Spot::factory()->count(2)->create(['xml_signature' => null]);

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andThrow(new NntpException('Authentication rejected', responseCode: 482, operation: 'HEAD'));

    $this->artisan('spot:enrich')
        ->assertFailed()
        ->expectsOutputToContain('unattempted spots were preserved');

    foreach ($spots as $spot) {
        expect($spot->fresh()->xml_signature)->toBeNull();
    }
});

test('enrich leaves articles without a completed callback intact after an interrupted stream', function () {
    $spots = Spot::factory()->count(2)->create(['xml_signature' => null])->sortBy('id')->values();

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturnUsing(function (array $messageIds, bool $showProgress, ?callable $onArticle): array {
            $onArticle($messageIds[0], HeadBatchResult::missing());

            return [];
        });

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    expect(Spot::find($spots[0]->id))->toBeNull()
        ->and($spots[1]->fresh()->xml_signature)->toBeNull();
});

test('enrich maps out-of-order callback IDs to their matching spots', function () {
    $missingSpot = Spot::factory()->create(['xml_signature' => null]);
    $enrichedSpot = Spot::factory()->create(['xml_signature' => null]);
    $xml = '<Spotnet><Posting><Title>Mapped</Title><Category>01</Category><NZB><Segment>nzb@news</Segment></NZB></Posting></Spotnet>';

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturnUsing(function (array $messageIds, bool $showProgress, ?callable $onArticle) use ($xml): array {
            $onArticle($messageIds[1], HeadBatchResult::success([
                'x-xml' => $xml,
                'x-xml-signature' => 'sig',
                'x-user-key' => '',
            ]));
            $onArticle($messageIds[0], HeadBatchResult::missing());

            return [];
        });

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    expect(Spot::find($missingSpot->id))->toBeNull()
        ->and($enrichedSpot->fresh()->description)->toBeNull()
        ->and($enrichedSpot->fresh()->xml_signature)->toBe('sig');
});

test('enrich flushes deletes at 500 completions and after the residual callbacks', function () {
    Spot::factory()->count(501)->create(['xml_signature' => null]);

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturnUsing(function (array $messageIds, bool $showProgress, ?callable $onArticle): array {
            foreach ($messageIds as $messageId) {
                $onArticle($messageId, HeadBatchResult::missing());
            }

            return [];
        });

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    $deleteQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_contains(strtolower($query), 'delete from "spots"'))
        ->values();

    DB::disableQueryLog();

    expect($deleteQueries)->toHaveCount(2)
        ->and(Spot::count())->toBe(0);
});
