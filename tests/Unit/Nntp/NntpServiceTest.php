<?php

declare(strict_types=1);

use App\Services\Nntp\NntpConnectionConfig;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\ParallelNntpDriver;
use App\Services\Nntp\SingleNntpDriver;

function nntpConfig(string $driver = 'parallel'): array
{
    return [
        'driver' => $driver,
        'host' => 'localhost',
        'port' => 563,
        'ssl' => true,
        'username' => '',
        'password' => '',
        'timeout' => 10,
        'connections' => 5,
        'groups' => ['spots' => 'free.pt', 'nzb' => 'alt.binaries.ftd'],
    ];
}

test('makeDriver returns ParallelNntpDriver for parallel driver by default', function () {
    $service = new NntpService(nntpConfig());

    expect($service->makeDriver())->toBeInstanceOf(ParallelNntpDriver::class);
});

test('makeDriver returns SingleNntpDriver for single driver', function () {
    $service = new NntpService(nntpConfig('single'));

    expect($service->makeDriver())->toBeInstanceOf(SingleNntpDriver::class);
});

test('makeDriver throws on unknown driver', function () {
    $service = new NntpService(nntpConfig('bogus'));

    $service->makeDriver();
})->throws(InvalidArgumentException::class, 'Unknown NNTP driver: bogus');

test('makeDriver respects connection count override', function () {
    $service = new NntpService(nntpConfig());

    $driver = $service->makeDriver(3);

    expect($driver->getConnectionCount())->toBe(0);
});

test('makeDriver with driver override returns SingleNntpDriver instance', function () {
    $service = new NntpService(nntpConfig());

    expect($service->makeDriver(driver: 'single'))->toBeInstanceOf(SingleNntpDriver::class);
});

test('getConfig returns the config array', function () {
    $config = nntpConfig();
    $service = new NntpService($config);

    expect($service->getConfig())->toBe($config);
});

test('typed connection config includes an optional alternate endpoint', function () {
    $config = nntpConfig();
    $config['alternate'] = [
        'host' => 'alternate.test',
        'port' => 119,
        'ssl' => false,
        'username' => 'backup',
        'password' => 'secret',
        'timeout' => 5,
    ];

    $typedConfig = NntpConnectionConfig::fromArray($config);
    $service = new NntpService($config);

    expect($typedConfig->primary->host)->toBe('localhost')
        ->and($typedConfig->alternate?->host)->toBe('alternate.test')
        ->and($service->makeAlternateDriver())->toBeInstanceOf(SingleNntpDriver::class);
});

test('alternate driver is absent when no alternate endpoint is configured', function () {
    expect((new NntpService(nntpConfig()))->makeAlternateDriver())->toBeNull();
});

test('NntpService is resolved from the container', function () {
    $service = app(NntpService::class);

    expect($service)->toBeInstanceOf(NntpService::class);
    expect($service->getConfig())->toBe(config('spotengine.nntp'));
});
