<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpDriverInterface;

class NntpService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * Create an NNTP driver instance.
     *
     * @param  int|null  $connections  Override the configured connection count (parallel driver only).
     * @param  string|null  $driver  Override the configured driver ('parallel' or 'single').
     *                               Defaults to the 'nntp.driver' config value.
     * @return ($driver is 'single' ? SingleNntpDriver : NntpDriverInterface)
     */
    public function makeDriver(?int $connections = null, ?string $driver = null): NntpDriverInterface
    {
        $driverType = $driver ?? ($this->config['driver'] ?? 'parallel');
        $numConnections = $connections ?? $this->config['connections'];

        return match ($driverType) {
            'parallel' => new ParallelNntpDriver($this->config, $numConnections),
            'single' => SingleNntpDriver::fromConfig($this->config),
            default => throw new \InvalidArgumentException("Unknown NNTP driver: {$driverType}"),
        };
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }
}
