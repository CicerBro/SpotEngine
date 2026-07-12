<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SingleNntpDriver;
use App\Services\SpotEnricher;
use App\Services\SpotMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
