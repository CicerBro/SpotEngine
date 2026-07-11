<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final readonly class NntpConnectionConfig
{
    /**
     * @param  array<string, string>  $groups
     */
    public function __construct(
        public NntpEndpoint $primary,
        public ?NntpEndpoint $alternate = null,
        public string $driver = 'parallel',
        public int $connections = 20,
        public array $groups = [],
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $alternate = isset($config['alternate']) && \is_array($config['alternate'])
            ? NntpEndpoint::fromArray($config['alternate'])
            : null;

        return new self(
            primary: NntpEndpoint::fromArray($config),
            alternate: $alternate,
            driver: (string) ($config['driver'] ?? 'parallel'),
            connections: max(1, min((int) ($config['connections'] ?? 20), 200)),
            groups: array_map(
                static fn (mixed $group): string => (string) $group,
                \is_array($config['groups'] ?? null) ? $config['groups'] : [],
            ),
        );
    }
}
