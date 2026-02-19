<?php

declare(strict_types=1);

namespace App\Services\Nntp;

use App\Services\Nntp\Contracts\NntpDriverInterface;

class NntpService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * Create a parallel NNTP driver instance based on the configured driver.
     *
     * @param  int|null  $connections  Override the configured connection count.
     */
    public function makeDriver(?int $connections = null): NntpDriverInterface
    {
        $numConnections = $connections ?? $this->config['connections'];

        return match ($this->config['driver'] ?? 'parallel-pipelined') {
            'parallel-pipelined' => new ParallelPipelinedNntp($this->config, $numConnections, 6),
            'parallel' => new ParallelNntp($this->config, $numConnections),
            default => throw new \InvalidArgumentException("Unknown NNTP driver: {$this->config['driver']}"),
        };
    }

    /**
     * Create a single-connection NNTP client for serial operations (BODY, NZB retrieval).
     */
    public function makeClient(): NntpClient
    {
        return NntpClient::fromConfig($this->config);
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }
}
