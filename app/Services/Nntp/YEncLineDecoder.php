<?php

declare(strict_types=1);

namespace App\Services\Nntp;

final class YEncLineDecoder
{
    public function decode(string $line, bool $rejectIncompleteEscape = true): string
    {
        $decoded = '';
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $value = ord($line[$index]);

            if ($value === 61) {
                if (++$index >= $length) {
                    if ($rejectIncompleteEscape) {
                        throw new \UnexpectedValueException('The yEnc line ends with an incomplete escape.');
                    }

                    break;
                }

                $value = (ord($line[$index]) - 64) & 0xFF;
            }

            $decoded .= chr(($value - 42) & 0xFF);
        }

        return $decoded;
    }
}
