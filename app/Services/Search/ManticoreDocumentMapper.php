<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Spot;

final class ManticoreDocumentMapper
{
    private const string BASE36_ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyz';

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     category: int,
     *     subcategories: list<int>,
     *     posted_at: int,
     *     file_size: int,
     *     has_nzb: bool
     * }
     */
    public function map(Spot $spot): array
    {
        return [
            'title' => $spot->title,
            'description' => $spot->description ?? '',
            'category' => $this->category($spot->category_code),
            'subcategories' => array_values(array_map(
                $this->subcategory(...),
                $spot->subcategories ?? [],
            )),
            'posted_at' => $spot->spot_posted_at->timestamp,
            'file_size' => $spot->file_size,
            'has_nzb' => ($spot->nzb_segments ?? []) !== [],
        ];
    }

    public function category(string $code): int
    {
        if (preg_match('/^\d+/', trim($code), $matches) !== 1) {
            return 0;
        }

        return (int) $matches[0];
    }

    public function subcategory(string $code): int
    {
        $normalized = strtolower(trim($code));
        $value = 0;

        foreach (str_split($normalized) as $character) {
            $digit = strpos(self::BASE36_ALPHABET, $character);

            if ($digit === false) {
                throw new \InvalidArgumentException("Invalid subcategory code [{$code}].");
            }

            $value = ($value * 36) + $digit;
        }

        return $value;
    }
}
