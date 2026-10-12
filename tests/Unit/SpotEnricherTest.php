<?php

declare(strict_types=1);

use App\Enums\SpotBanType;
use App\Enums\SpotListKind;
use App\Models\Spot;
use App\Models\SpotBan;
use App\Services\Nntp\NntpException;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\Nntp\SpotterId;
use App\Services\SpotEnricher;
use App\Services\SpotMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

test('an already enriched banned poster cannot access content', function () {
    SpotBan::query()->create(['type' => SpotBanType::PosterKeyId, 'value' => 'blocked']);
    $spot = Spot::factory()->create(['poster_key_id' => 'blocked', 'xml_signature' => 'sig']);

    try {
        app(SpotEnricher::class)->enrich($spot);
        $this->fail('Expected banned spot to be unavailable.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }
});

test('whitelisted keys retain access despite poster and tag bans', function () {
    SpotBan::query()->create(['type' => SpotBanType::Tag, 'value' => 'blocked']);
    SpotBan::query()->create(['kind' => SpotListKind::Whitelist, 'type' => SpotBanType::PosterKeyId, 'value' => 'allowed']);
    $spot = Spot::factory()->create(['tag' => 'blocked', 'poster_key_id' => 'allowed', 'xml_signature' => 'sig']);

    expect(app(SpotEnricher::class)->enrich($spot))->toBeFalse();
});

test('temporary HEAD failures leave lazy enrichment retryable', function () {
    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('connect')->times(4)->with(false);
    $driver->shouldReceive('group')->times(4)->with('free.pt');
    $driver->shouldReceive('quit')->times(3);
    $driver->shouldReceive('head')->times(3)->andThrow(new NntpException('Unavailable', responseCode: 400, operation: 'HEAD'));
    $driver->shouldReceive('head')->once()->andReturn([
        'x-xml' => '<Spotnet><Posting><Title>Retry</Title><Category>01</Category><NZB><Segment>nzb@news</Segment></NZB></Posting></Spotnet>',
    ]);
    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('makeDriver')->times(4)->with(null, 'single')->andReturn($driver);
    $nntpService->shouldReceive('getConfig')->andReturn(['groups' => ['spots' => 'free.pt']]);
    $spot = Spot::factory()->create(['description' => null, 'nzb_segments' => [], 'xml_signature' => null]);
    $enricher = app(SpotEnricher::class, ['nntpService' => $nntpService]);

    expect($enricher->enrich($spot))->toBeFalse();
    expect($spot->fresh()->xml_signature)->toBeNull();
    expect($enricher->enrich($spot))->toBeTrue();
    expect($spot->fresh()->nzb_segments)->toBe(['nzb@news']);
});

test('lazy enrichment rejects a newly discovered blacklisted key before persisting content', function () {
    SpotBan::query()->create(['type' => SpotBanType::PosterKeyId, 'value' => SpotterId::fromModulus('AQAB')]);
    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('connect')->once()->with(false);
    $driver->shouldReceive('group')->once()->with('free.pt');
    $driver->shouldReceive('head')->once()->andReturn([
        'x-xml' => '<Spotnet><Posting><Title>Banned</Title><Category>01</Category><NZB><Segment>nzb@news</Segment></NZB></Posting></Spotnet>',
        'x-user-key' => '<RSAKeyValue><Modulus>AQAB</Modulus><Exponent>AQAB</Exponent></RSAKeyValue>',
    ]);
    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('makeDriver')->once()->with(null, 'single')->andReturn($driver);
    $nntpService->shouldReceive('getConfig')->andReturn(['groups' => ['spots' => 'free.pt']]);
    $spot = Spot::factory()->create(['description' => null, 'nzb_segments' => [], 'xml_signature' => null]);

    try {
        app(SpotEnricher::class, ['nntpService' => $nntpService])->enrich($spot);
        $this->fail('Expected banned spot to be unavailable.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(404);
    }

    expect($spot->fresh()->nzb_segments)->toBe([])
        ->and($spot->fresh()->poster_key_id)->toBeNull();
});

test('definitively missing HEAD articles are marked final without retrying', function () {
    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('connect')->once()->with(false);
    $driver->shouldReceive('group')->once()->with('free.pt');
    $driver->shouldReceive('head')->once()->andThrow(new NntpException('Missing', responseCode: 430, operation: 'HEAD'));
    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('makeDriver')->once()->with(null, 'single')->andReturn($driver);
    $nntpService->shouldReceive('getConfig')->andReturn(['groups' => ['spots' => 'free.pt']]);
    $spot = Spot::factory()->create(['description' => null, 'nzb_segments' => [], 'xml_signature' => null]);
    $enricher = app(SpotEnricher::class, ['nntpService' => $nntpService]);

    expect($enricher->enrich($spot))->toBeFalse()
        ->and($enricher->enrich($spot))->toBeFalse();
    expect($spot->fresh()->xml_signature)->toBe('');
});

test('spot enricher reuses a single connection within one service instance', function () {
    $driver = Mockery::mock(SingleNntpDriver::class);
    $driver->shouldReceive('isConnected')->once()->andReturn(true);
    $driver->shouldReceive('connect')->once()->with(false);
    $driver->shouldReceive('group')->once()->with('free.pt');
    $driver->shouldReceive('head')->twice()->andReturn([
        'x-xml' => '<Spotnet><Posting><Title>One</Title><Category>01</Category><NZB><Segment>nzb@news</Segment></NZB></Posting></Spotnet>',
        'x-xml-signature' => 'sig',
        'x-user-key' => '',
    ]);
    $driver->shouldNotReceive('quit');

    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('makeDriver')->once()->with(null, 'single')->andReturn($driver);
    $nntpService->shouldReceive('getConfig')->andReturn([
        'groups' => ['spots' => 'free.pt'],
    ]);

    $spots = Spot::factory()->count(2)->create([
        'xml_signature' => null,
        'description' => null,
        'nzb_segments' => [],
    ]);

    $enricher = app(SpotEnricher::class, [
        'nntpService' => $nntpService,
        'spotMutations' => app(SpotMutationService::class),
    ]);

    expect($enricher->enrich($spots[0]))->toBeTrue()
        ->and($enricher->enrich($spots[1]))->toBeTrue();
});

test('spot enricher does not share its connection across service instances', function () {
    $firstDriver = Mockery::mock(SingleNntpDriver::class);
    $firstDriver->shouldReceive('connect')->once()->with(false);
    $firstDriver->shouldReceive('group')->once()->with('free.pt');
    $firstDriver->shouldReceive('head')->once()->andReturn([
        'x-xml' => '<Spotnet><Posting><Title>One</Title><Category>01</Category><NZB><Segment>nzb@news</Segment></NZB></Posting></Spotnet>',
    ]);

    $secondDriver = Mockery::mock(SingleNntpDriver::class);
    $secondDriver->shouldReceive('connect')->once()->with(false);
    $secondDriver->shouldReceive('group')->once()->with('free.pt');
    $secondDriver->shouldReceive('head')->once()->andReturn([
        'x-xml' => '<Spotnet><Posting><Title>Two</Title><Category>01</Category><NZB><Segment>nzb@news</Segment></NZB></Posting></Spotnet>',
    ]);

    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('makeDriver')->twice()->with(null, 'single')->andReturn($firstDriver, $secondDriver);
    $nntpService->shouldReceive('getConfig')->andReturn(['groups' => ['spots' => 'free.pt']]);

    $spots = Spot::factory()->count(2)->create(['xml_signature' => null, 'description' => null, 'nzb_segments' => []]);
    $firstEnricher = app(SpotEnricher::class, [
        'nntpService' => $nntpService,
        'spotMutations' => app(SpotMutationService::class),
    ]);
    $secondEnricher = app(SpotEnricher::class, [
        'nntpService' => $nntpService,
        'spotMutations' => app(SpotMutationService::class),
    ]);

    expect($firstEnricher->enrich($spots[0]))->toBeTrue()
        ->and($secondEnricher->enrich($spots[1]))->toBeTrue();
});

test('spot enricher reconnects after a head failure', function () {
    $firstDriver = Mockery::mock(SingleNntpDriver::class);
    $firstDriver->shouldReceive('isConnected')->andReturn(true);
    $firstDriver->shouldReceive('connect')->once()->with(false);
    $firstDriver->shouldReceive('group')->once()->with('free.pt');
    $firstDriver->shouldReceive('head')->once()->andThrow(new RuntimeException('connection lost'));
    $firstDriver->shouldReceive('quit')->once();

    $secondDriver = Mockery::mock(SingleNntpDriver::class);
    $secondDriver->shouldReceive('isConnected')->andReturn(true);
    $secondDriver->shouldReceive('connect')->once()->with(false);
    $secondDriver->shouldReceive('group')->once()->with('free.pt');
    $secondDriver->shouldReceive('head')->once()->andReturn([
        'x-xml' => '<Spotnet><Posting><Title>Retry</Title><Category>01</Category><NZB><Segment>nzb@news</Segment></NZB></Posting></Spotnet>',
        'x-xml-signature' => 'sig',
        'x-user-key' => '',
    ]);
    $secondDriver->shouldNotReceive('quit');

    $nntpService = Mockery::mock(NntpService::class);
    $nntpService->shouldReceive('makeDriver')->twice()->with(null, 'single')->andReturn($firstDriver, $secondDriver);
    $nntpService->shouldReceive('getConfig')->andReturn([
        'groups' => ['spots' => 'free.pt'],
    ]);

    $spot = Spot::factory()->create([
        'xml_signature' => null,
        'description' => null,
        'nzb_segments' => [],
    ]);

    $enricher = app(SpotEnricher::class, [
        'nntpService' => $nntpService,
        'spotMutations' => app(SpotMutationService::class),
    ]);

    expect($enricher->enrich($spot))->toBeTrue();
});
