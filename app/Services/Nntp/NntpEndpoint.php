<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final readonly class NntpEndpoint
{
    public function __construct(
        public string $host,
        public int $port = 563,
        public bool $ssl = true,
        public string $username = '',
        public string $password = '',
        public int $timeout = 60,
        public bool $verifyPeer = true,
        public bool $allowSelfSigned = false,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        return new self(
            host: (string) ($config['host'] ?? ''),
            port: (int) ($config['port'] ?? 563),
            ssl: (bool) ($config['ssl'] ?? true),
            username: (string) ($config['username'] ?? ''),
            password: (string) ($config['password'] ?? ''),
            timeout: (int) ($config['timeout'] ?? 60),
            verifyPeer: (bool) ($config['verify_peer'] ?? true),
            allowSelfSigned: (bool) ($config['allow_self_signed'] ?? false),
        );
    }

    public function address(): string
    {
        return ($this->ssl ? 'ssl' : 'tcp')."://{$this->host}:{$this->port}";
    }

    /** @return array{ssl: array{verify_peer: bool, verify_peer_name: bool, allow_self_signed: bool, peer_name: string}} */
    public function streamContextOptions(): array
    {
        return [
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => $this->allowSelfSigned,
                'peer_name' => $this->host,
            ],
        ];
    }
}
