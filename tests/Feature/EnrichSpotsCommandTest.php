<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\NntpService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    $this->mockDriver->shouldReceive('headParallel')
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
        ->and($spot->nzb_segments)->toBe(['nzb-segment@news']);
});

test('enrich reports all spots enriched when none are unenriched', function () {
    $this->artisan('spot:enrich')
        ->assertSuccessful()
        ->expectsOutputToContain('All spots are already enriched');
});

test('enrich marks failed HEAD with empty xml_signature and preserves title', function () {
    $spot = Spot::factory()->create([
        'title' => 'Keep This Title',
        'xml_signature' => null,
    ]);

    $this->mockDriver->shouldReceive('headParallel')
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

    $this->mockDriver->shouldReceive('headParallel')
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
