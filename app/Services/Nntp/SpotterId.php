<?php

declare(strict_types=1);

namespace App\Services\Nntp;

/**
 * Derives a Spotnet spotter ID from an RSA modulus, matching Spotweb.
 */
final class SpotterId
{
    public static function fromModulus(string $preparedModulus): ?string
    {
        $modulus = base64_decode(
            str_replace(['-p', '-s', '-e'], ['+', '/', '='], trim($preparedModulus)),
            true,
        );

        if ($modulus === false || $modulus === '') {
            return null;
        }

        $spotterId = str_replace(
            ['/', '+', '='],
            '',
            base64_encode(pack('V', crc32($modulus))),
        );

        $length = strlen($spotterId);

        if ($spotterId === '' || $length < 3 || $length > 6) {
            return null;
        }

        return $spotterId;
    }
}
