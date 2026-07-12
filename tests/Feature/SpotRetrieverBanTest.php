<?php

declare(strict_types=1);

use App\Enums\SpotBanType;
use App\Models\SpotBan;
use App\Services\Nntp\Contracts\NntpDriverInterface;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\SigningService;
use App\Services\Nntp\SpotParser;
use App\Services\SpotBanService;
use App\Services\SpotMutationService;
use App\Services\SpotRetrieverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('spot retrieval skips spots with banned tags during indexing', function () {
    SpotBan::query()->create([
        'kind' => SpotListKind::Blacklist,
        'type' => SpotBanType::Tag,
        'value' => 'timskuik',
    ]);

    $allowedOverview = [
        'from' => 'Allowed Poster <abc123@17a11b03c04d44z02.110917840.20.1771524587.1.NL.Sig123>',
        'subject' => 'Allowed Spot Title',
        'date' => 'Wed, 19 Feb 2026 12:00:00 +0000',
        'message_id' => 'allowed@example.com',
    ];

    $bannedOverview = [
        'from' => 'Spammer <spam@17a11b03c04d44z02.110917840.20.1771524587.1.NL.Sig123>',
        'subject' => 'Tim kuiks Oplossing|timskuik',
        'date' => 'Wed, 19 Feb 2026 12:00:00 +0000',
        'message_id' => 'banned@example.com',
    ];

    $driver = Mockery::mock(NntpDriverInterface::class);
    $driver->shouldReceive('xover')
        ->once()
        ->with(1, 2)
        ->andReturn([
            1 => $bannedOverview,
            2 => $allowedOverview,
        ]);

    $service = new BanTestSpotRetriever;
    $service->setNntpDriver($driver);

    $method = new ReflectionMethod($service, 'fetchBatch');
    $method->setAccessible(true);

    [$processed, $spots] = $method->invoke($service, 1, 2);

    expect($processed)->toBe(2)
        ->and($spots)->toHaveCount(1)
        ->and($spots[0]['message_id'])->toBe('allowed@example.com');
});

class BanTestSpotRetriever extends SpotRetrieverService
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

        $this->initialScan = true;
    }

    public function setNntpDriver(NntpDriverInterface $driver): void
    {
        $this->nntp = $driver;
    }
}
