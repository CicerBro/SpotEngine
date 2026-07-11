<?php

declare(strict_types=1);

use App\Services\Nntp\NntpEndpoint;

test('NNTP endpoints verify certificates by default', function () {
    $endpoint = NntpEndpoint::fromArray(['host' => 'news.example.com']);

    expect($endpoint->verifyPeer)->toBeTrue()
        ->and($endpoint->allowSelfSigned)->toBeFalse()
        ->and($endpoint->streamContextOptions()['ssl'])->toBe([
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => 'news.example.com',
        ]);
});
