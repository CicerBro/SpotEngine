<?php

declare(strict_types=1);

use App\Services\Nntp\NntpClient;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\ParallelNntp;
use App\Services\Nntp\ParallelPipelinedNntp;

function nntpConfig(string $driver = 'parallel-pipelined'): array
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

test('makeDriver returns ParallelPipelinedNntp by default', function () {
    $service = new NntpService(nntpConfig());

    expect($service->makeDriver())->toBeInstanceOf(ParallelPipelinedNntp::class);
});

test('makeDriver returns ParallelNntp for parallel driver', function () {
    $service = new NntpService(nntpConfig('parallel'));

    expect($service->makeDriver())->toBeInstanceOf(ParallelNntp::class);
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

test('makeClient returns NntpClient instance', function () {
    $service = new NntpService(nntpConfig());

    expect($service->makeClient())->toBeInstanceOf(NntpClient::class);
});

test('getConfig returns the config array', function () {
    $config = nntpConfig();
    $service = new NntpService($config);

    expect($service->getConfig())->toBe($config);
});

test('NntpService is resolved from the container', function () {
    $service = app(NntpService::class);

    expect($service)->toBeInstanceOf(NntpService::class);
    expect($service->getConfig())->toBe(config('spotengine.nntp'));
});
