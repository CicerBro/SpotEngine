<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\NntpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->mockDriver = Mockery::mock(NntpDriverInterface::class);
    $this->mockDriver->shouldReceive('connect');
    $this->mockDriver->shouldReceive('quit');

    $mockNntpService = Mockery::mock(NntpService::class);
    $mockNntpService->shouldReceive('getConfig')->andReturn([
        'connections' => 1,
    ]);
    $mockNntpService->shouldReceive('makeDriver')->andReturn($this->mockDriver);

    $this->app->instance(NntpService::class, $mockNntpService);
});

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

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturn([
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

test('enrich can process spots in descending posted date order', function () {
    $olderSpot = Spot::factory()->create([
        'xml_signature' => null,
        'spot_posted_at' => now()->subDay(),
    ]);
    $newerSpot = Spot::factory()->create([
        'xml_signature' => null,
        'spot_posted_at' => now(),
    ]);

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->with([$newerSpot->message_id], false)
        ->andReturn([
            $newerSpot->message_id => null,
        ]);

    $this->artisan('spot:enrich --desc --limit=1')
        ->assertSuccessful();

    expect($newerSpot->fresh()->xml_signature)->toBe('')
        ->and($olderSpot->fresh()->xml_signature)->toBeNull();
});

test('enrich advances through batches using a posted-date cursor', function () {
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
        ->with([$firstSpot->message_id, $secondSpot->message_id], false)
        ->ordered()
        ->andReturn([
            $firstSpot->message_id => null,
            $secondSpot->message_id => null,
        ]);
    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->with([$thirdSpot->message_id], false)
        ->ordered()
        ->andReturn([
            $thirdSpot->message_id => null,
        ]);

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
        ->and($batchQueries->last())->toContain('"spot_posted_at" > ?');
});

test('enrich marks failed HEAD with empty xml_signature and preserves title', function () {
    $spot = Spot::factory()->create([
        'title' => 'Keep This Title',
        'xml_signature' => null,
    ]);

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturn([
            $spot->message_id => null,
        ]);

    $this->artisan('spot:enrich')
        ->assertSuccessful();

    $spot->refresh();

    expect($spot->title)->toBe('Keep This Title')
        ->and($spot->xml_signature)->toBe('');
});

test('enrich handles mixed failed and successful HEAD results in same batch', function () {
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

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturn([
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

    $failedSpot->refresh();
    $successSpot->refresh();

    expect($failedSpot->xml_signature)->toBe('')
        ->and($failedSpot->title)->toBe('Failed Spot')
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

    $this->mockDriver->shouldReceive('headBatch')
        ->once()
        ->andReturn([
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
