<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Spotweb category definitions.
 *
 * Fetches and parses structure from Spotweb SpotCategories.php:
 *
 * @see https://github.com/spotweb/spotweb/blob/develop/lib/SpotCategories.php
 *
 * Used by spot:categories:update to sync categories to the database.
 */
class SpotwebCategories
{
    public const string SOURCE_URL = 'https://raw.githubusercontent.com/spotweb/spotweb/develop/lib/SpotCategories.php';

    private const int FETCH_TIMEOUT_SECONDS = 10;

    private const int FETCH_CONNECT_TIMEOUT_SECONDS = 3;

    /**
     * Fetch the latest Spotweb definitions and flatten them into category rows.
     *
     * @return array<int, array{code: string, parent_code: string|null, name: string, slug: string, type: string|null, sort_order: int}>
     */
    public static function fetchCategoryRows(?string $sourceUrl = null): array
    {
        $response = Http::retry([100, 500, 1000])
            ->timeout(self::FETCH_TIMEOUT_SECONDS)
            ->connectTimeout(self::FETCH_CONNECT_TIMEOUT_SECONDS)
            ->get($sourceUrl ?? self::SOURCE_URL)
            ->throw();

        return self::fromSpotCategoriesPhp($response->body());
    }

    /**
     * Parse Spotweb's PHP category file and flatten it into category rows.
     *
     * @return array<int, array{code: string, parent_code: string|null, name: string, slug: string, type: string|null, sort_order: int}>
     */
    public static function fromSpotCategoriesPhp(string $contents): array
    {
        /** @var array<int, string> $headNames */
        $headNames = self::extractStaticArray($contents, '_head_categories');

        /** @var array<int, array<string, string>> $subcatDescriptions */
        $subcatDescriptions = self::extractStaticArray($contents, '_subcat_descriptions');

        /** @var array<int, array<string, array<int|string, mixed>>> $categories */
        $categories = self::extractStaticArray($contents, '_categories');

        return self::toCategoryRows($headNames, $subcatDescriptions, $categories);
    }

    /**
     * Flatten Spotweb categories into rows for our categories table.
     *
     * @param  array<int, string>  $headNames
     * @param  array<int, array<string, string>>  $subcatDescriptions
     * @param  array<int, array<string, array<int|string, mixed>>>  $categories
     * @return array<int, array{code: string, parent_code: string|null, name: string, slug: string, type: string|null, sort_order: int}>
     */
    public static function toCategoryRows(array $headNames, array $subcatDescriptions, array $categories): array
    {
        $rows = [];
        $sortOrder = 1;
        $usedSlugs = [];

        foreach ($headNames as $hcat => $headName) {
            $code = self::headCode((int) $hcat);
            $slug = self::uniqueSlug(Str::slug($headName), $usedSlugs);
            $rows[] = [
                'code' => $code,
                'parent_code' => null,
                'name' => $headName,
                'slug' => $slug,
                'type' => null,
                'sort_order' => $sortOrder++,
            ];

            $subcatSort = 1;
            foreach ($categories[(int) $hcat] ?? [] as $letter => $items) {
                $type = $subcatDescriptions[(int) $hcat][$letter] ?? null;
                $typeSlug = $type !== null ? self::descriptionToType($type) : null;

                foreach ($items as $index => $value) {
                    $numIndex = is_numeric($index) ? (int) $index : 0;
                    $name = self::categoryName($value);
                    $displayName = ($name === '' || $name === '-') ? '-' : $name;
                    $slug = self::uniqueSlug(
                        ($name === '' || $name === '-') ? $letter . \sprintf('%02d', $numIndex) : Str::slug($name),
                        $usedSlugs
                    );

                    $rows[] = [
                        'code' => $code . $letter . \sprintf('%02d', $numIndex),
                        'parent_code' => $code,
                        'name' => $displayName,
                        'slug' => $slug,
                        'type' => $typeSlug,
                        'sort_order' => $subcatSort++,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<mixed>
     */
    private static function extractStaticArray(string $contents, string $property): array
    {
        if (! preg_match('/\\$' . preg_quote($property, '/') . '\\s*=\\s*\\[/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException("Spotweb category property [{$property}] was not found.");
        }

        $literalStart = $matches[0][1] + strlen($matches[0][0]) - 1;
        $array = self::parseArrayLiteral(self::extractBalancedArrayLiteral($contents, $literalStart));

        if (! \is_array($array)) {
            throw new RuntimeException("Spotweb category property [{$property}] is not an array.");
        }

        return $array;
    }

    private static function extractBalancedArrayLiteral(string $contents, int $start): string
    {
        if (($contents[$start] ?? null) !== '[') {
            throw new RuntimeException('Spotweb category array literal does not start with an opening bracket.');
        }

        $depth = 0;
        $quote = null;
        $length = strlen($contents);

        for ($position = $start; $position < $length; $position++) {
            $char = $contents[$position];

            if ($quote !== null) {
                if ($char === '\\') {
                    $position++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;

                continue;
            }

            if ($char === '[') {
                $depth++;

                continue;
            }

            if ($char === ']') {
                $depth--;

                if ($depth === 0) {
                    return substr($contents, $start, $position - $start + 1);
                }
            }
        }

        throw new RuntimeException('Spotweb category array literal was not closed.');
    }

    private static function parseArrayLiteral(string $literal): mixed
    {
        $tokens = self::arrayLiteralTokens($literal);
        $index = 0;
        $value = self::parseArrayValue($tokens, $index);

        if ($index !== \count($tokens)) {
            throw new RuntimeException('Spotweb category array contains unexpected trailing tokens.');
        }

        return $value;
    }

    /**
     * @return list<array{id: int|null, text: string}>
     */
    private static function arrayLiteralTokens(string $literal): array
    {
        return array_values(array_filter(
            array_map(
                fn (array|string $token): array => \is_array($token)
                    ? ['id' => $token[0], 'text' => $token[1]]
                    : ['id' => null, 'text' => $token],
                token_get_all('<?php ' . $literal)
            ),
            fn (array $token): bool => ! \in_array($token['id'], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /**
     * @param  list<array{id: int|null, text: string}>  $tokens
     */
    private static function parseArrayValue(array $tokens, int &$index): mixed
    {
        $token = $tokens[$index] ?? null;

        if ($token === null) {
            throw new RuntimeException('Spotweb category array ended unexpectedly.');
        }

        if ($token['text'] === '[') {
            return self::parsePhpArray($tokens, $index);
        }

        $index++;

        return match ($token['id']) {
            T_CONSTANT_ENCAPSED_STRING => self::parsePhpString($token['text']),
            T_LNUMBER => (int) $token['text'],
            T_DNUMBER => (float) $token['text'],
            T_STRING => self::parsePhpConstant($token['text']),
            default => throw new RuntimeException("Unsupported Spotweb category token [{$token['text']}]."),
        };
    }

    /**
     * @param  list<array{id: int|null, text: string}>  $tokens
     * @return array<mixed>
     */
    private static function parsePhpArray(array $tokens, int &$index): array
    {
        self::expectToken($tokens, $index, '[');

        $array = [];

        while (! self::nextTokenIs($tokens, $index, ']')) {
            if (self::nextTokenIs($tokens, $index, ',')) {
                $index++;

                continue;
            }

            $keyOrValue = self::parseArrayValue($tokens, $index);

            if (self::nextTokenIs($tokens, $index, '=>')) {
                $index++;

                if (! \is_int($keyOrValue) && ! \is_string($keyOrValue)) {
                    throw new RuntimeException('Spotweb category array key must be a string or integer.');
                }

                $array[$keyOrValue] = self::parseArrayValue($tokens, $index);
            } else {
                $array[] = $keyOrValue;
            }

            if (self::nextTokenIs($tokens, $index, ',')) {
                $index++;

                continue;
            }

            if (! self::nextTokenIs($tokens, $index, ']')) {
                $text = $tokens[$index]['text'] ?? 'EOF';

                throw new RuntimeException("Expected comma or closing bracket in Spotweb category array, got [{$text}].");
            }
        }

        self::expectToken($tokens, $index, ']');

        return $array;
    }

    /**
     * @param  list<array{id: int|null, text: string}>  $tokens
     */
    private static function expectToken(array $tokens, int &$index, string $expected): void
    {
        if (! self::nextTokenIs($tokens, $index, $expected)) {
            $text = $tokens[$index]['text'] ?? 'EOF';

            throw new RuntimeException("Expected [{$expected}] in Spotweb category array, got [{$text}].");
        }

        $index++;
    }

    /**
     * @param  list<array{id: int|null, text: string}>  $tokens
     */
    private static function nextTokenIs(array $tokens, int $index, string $expected): bool
    {
        return ($tokens[$index]['text'] ?? null) === $expected;
    }

    private static function parsePhpString(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);

        if ($quote === '\'') {
            return str_replace(['\\\\', '\\\''], ['\\', '\''], $value);
        }

        return stripcslashes($value);
    }

    private static function parsePhpConstant(string $constant): mixed
    {
        return match (strtolower($constant)) {
            'false' => false,
            'null' => null,
            'true' => true,
            default => throw new RuntimeException("Unsupported Spotweb category constant [{$constant}]."),
        };
    }

    private static function categoryName(mixed $value): string
    {
        if (\is_array($value)) {
            return (string) ($value[0] ?? '');
        }

        return (string) $value;
    }

    /**
     * Head category index to our 2-digit code (01, 02, 03, 04).
     */
    private static function headCode(int $hcat): string
    {
        return str_pad((string) ($hcat + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Map Spotweb subcat description to our type (lowercase slug).
     */
    private static function descriptionToType(string $description): string
    {
        return Str::slug($description, '_');
    }

    private static function uniqueSlug(string $base, array &$used): string
    {
        $slug = $base;
        $suffix = 0;
        while (isset($used[$slug])) {
            $slug = $base . '-' . (++$suffix);
        }
        $used[$slug] = true;

        return $slug;
    }
}
