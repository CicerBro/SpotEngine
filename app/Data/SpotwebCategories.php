<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Support\Str;

/**
 * Spotweb category definitions.
 *
 * Mirrors structure from Spotweb SpotCategories.php:
 * https://github.com/spotweb/spotweb/blob/develop/lib/SpotCategories.php
 *
 * Used by spot:categories:update to sync categories to the database.
 */
class SpotwebCategories
{
    private const array HEAD_NAMES = [
        0 => 'Image',
        1 => 'Sound',
        2 => 'Games',
        3 => 'Applications',
    ];

    private const array SUBCAT_DESCRIPTIONS = [
        0 => ['a' => 'Format', 'b' => 'Source', 'c' => 'Language', 'd' => 'Genre', 'z' => 'Type'],
        1 => ['a' => 'Format', 'b' => 'Source', 'c' => 'Bitrate', 'd' => 'Genre', 'z' => 'Type'],
        2 => ['a' => 'Platform', 'b' => 'Format', 'c' => 'Genre', 'z' => 'Type'],
        3 => ['a' => 'Platform', 'b' => 'Genre', 'z' => 'Type'],
    ];

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

    /**
     * Flatten Spotweb categories into rows for our categories table.
     *
     * @return array<int, array{code: string, parent_code: string|null, name: string, slug: string, type: string|null, sort_order: int}>
     */
    public static function toCategoryRows(): array
    {
        $rows = [];
        $sortOrder = 1;
        $usedSlugs = [];

        foreach (self::HEAD_NAMES as $hcat => $headName) {
            $code = self::headCode($hcat);
            $slug = self::uniqueSlug(Str::slug($headName), $usedSlugs);
            $rows[] = [
                'code' => $code,
                'parent_code' => null,
                'name' => $headName,
                'slug' => $slug,
                'type' => null,
                'sort_order' => $sortOrder++,
            ];

            $subcats = self::getSubcategories($hcat);
            $subcatSort = 1;
            foreach ($subcats as $letter => $items) {
                $type = self::SUBCAT_DESCRIPTIONS[$hcat][$letter] ?? null;
                $typeSlug = $type !== null ? self::descriptionToType($type) : null;

                foreach ($items as $index => $name) {
                    $numIndex = is_numeric($index) ? (int) $index : 0;
                    $subCode = $code.$letter.\sprintf('%02d', $numIndex);
                    $displayName = ($name === '' || $name === '-') ? '-' : $name;
                    $slug = self::uniqueSlug(
                        ($name === '' || $name === '-') ? $letter.\sprintf('%02d', $numIndex) : Str::slug($name),
                        $usedSlugs
                    );
                    $rows[] = [
                        'code' => $subCode,
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

    private static function uniqueSlug(string $base, array &$used): string
    {
        $slug = $base;
        $suffix = 0;
        while (isset($used[$slug])) {
            $slug = $base.'-'.(++$suffix);
        }
        $used[$slug] = true;

        return $slug;
    }

    /**
     * Subcategory definitions per head category.
     * Structure: [letter => [index => name, ...], ...]
     *
     * @return array<string, array<int|string, string>>
     */
    private static function getSubcategories(int $hcat): array
    {
        $out = [];
        $data = self::categoriesData();

        if (! isset($data[$hcat])) {
            return $out;
        }

        foreach ($data[$hcat] as $letter => $indices) {
            $names = [];
            foreach ($indices as $idx => $value) {
                $name = \is_array($value) ? ($value[0] ?? '') : (string) $value;
                $numIdx = is_numeric($idx) ? (int) $idx : 0;
                $names[$numIdx] = $name;
            }
            $out[$letter] = $names;
        }

        return $out;
    }

    /**
    /**
     * Returns the full Spotweb $_categories structure.
     *
     * The structure is:
     * - head: integer key (the main or 'head' category, e.g. 0=Video, 1=Audio, etc.)
     *   - letter: string key representing a subcategory group (e.g. 'a', 'b', 'c', 'd', 'z')
     *     - index: integer or string key within the letter group (e.g. 0, 1, 2, ...)
     *       - value: array with the subcategory name as the first element (sometimes with additional data), or just a string (category name)
     *
     * Example: $categories[0]['a'][4][0] == "HD other"
     *
     * Meaning of keys:
     * - head: top-level category (int)
     * - letter: grouped subcategory type (string, e.g. 'a', 'b', etc., defined by Spotweb)
     * - index: subcategory index within the letter (int|string)
     * - value: subcategory name or array of subcategory data (mixed)
     *
     * @see https://github.com/spotweb/spotweb/blob/develop/lib/SpotCategories.php
     *
     * @return array<int, array<string, array<int|string, mixed>>>
     */
    private static function categoriesData(): array
    {
        return [
            0 => [
                'a' => [
                    0 => ['DivX'], 1 => ['WMV'], 2 => ['MPG'], 3 => ['DVD5'], 4 => ['HD other'], 5 => ['ePub'],
                    6 => ['Blu-ray'], 7 => ['HD-DVD'], 8 => ['WMVHD'], 9 => ['x264'], 10 => ['DVD9'],
                    11 => ['PDF'], 12 => ['Bitmap'], 13 => ['Vector'], 14 => ['3D'], 15 => ['UHD'],
                ],
                'b' => [
                    0 => ['CAM'], 1 => ['(S)VCD'], 2 => ['Promo'], 3 => ['Retail'], 4 => ['TV'], 5 => ['-'],
                    6 => ['Satellite'], 7 => ['R5'], 8 => ['Telecine'], 9 => ['Telesync'], 10 => ['Scan'],
                    11 => ['WEB-DL'], 12 => ['WEBRip'], 13 => ['HDRip'],
                ],
                'c' => [
                    0 => ['No subtitles'], 1 => ['Dutch subtitles (external)'], 2 => ['Dutch subtitles (builtin)'],
                    3 => ['English subtitles (external)'], 4 => ['English subtitles (builtin)'], 5 => ['-'],
                    6 => ['Dutch subtitles (available)'], 7 => ['English subtitles (available)'], 8 => ['-'], 9 => ['-'],
                    10 => ['English audio/written'], 11 => ['Dutch audio/written'], 12 => ['German audio/written'],
                    13 => ['French audio/written'], 14 => ['Spanish audio/written'], 15 => ['Asian audio/written'],
                ],
                'd' => [
                    0 => ['Action'], 1 => ['Adventure'], 2 => ['Animation'], 3 => ['Cabaret'], 4 => ['Comedy'],
                    5 => ['Crime'], 6 => ['Documentary'], 7 => ['Drama'], 8 => ['Family'], 9 => ['Fantasy'],
                    10 => ['Arthouse'], 11 => ['Television'], 12 => ['Horror'], 13 => ['Music'], 14 => ['Musical'],
                    15 => ['Mystery'], 16 => ['Romance'], 17 => ['Science Fiction'], 18 => ['Sport'],
                    19 => ['Short movie'], 20 => ['Thriller'], 21 => ['War'], 22 => ['Western'],
                    23 => ['Erotica (hetero)'], 24 => ['Erotica (gay male)'], 25 => ['Erotica (gay female)'],
                    26 => ['Erotica (bi)'], 27 => ['-'], 28 => ['Asian'], 29 => ['Anime'], 30 => ['Cover'],
                    31 => ['Comicbook'], 32 => ['Cartoons'], 33 => ['Youth'], 34 => ['Business'], 35 => ['Computer'],
                    36 => ['Hobby'], 37 => ['Cooking'], 38 => ['Handwork'], 39 => ['Craftwork'], 40 => ['Health'],
                    41 => ['History'], 42 => ['Psychology'], 43 => ['Newspaper'], 44 => ['Magazine'],
                    45 => ['Science'], 46 => ['Female'], 47 => ['Religion'], 48 => ['Roman'], 49 => ['Biography'],
                    50 => ['Detective'], 51 => ['Animals'], 52 => ['Humor'], 53 => ['Travel'], 54 => ['True story'],
                    55 => ['Non-fiction'], 56 => ['Politics'], 57 => ['Poetry'], 58 => ['Fairy tale'],
                    59 => ['Technical'], 60 => ['Art'],
                    72 => ['Bi'], 73 => ['Lesbian'], 74 => ['Homo'], 75 => ['Hetero'], 76 => ['Amature'],
                    77 => ['Group'], 78 => ['POV'], 79 => ['Solo'], 80 => ['Young'], 81 => ['Soft'],
                    82 => ['Fetish'], 83 => ['Old'], 84 => ['Fat'], 85 => ['SM'], 86 => ['Rough'],
                    87 => ['Dark'], 88 => ['Hentai'], 89 => ['Outside'],
                ],
                'z' => [0 => 'Movie', 1 => 'Series', 2 => 'Book', 3 => 'Erotica', 4 => 'Picture'],
            ],
            1 => [
                'a' => [
                    0 => ['MP3'], 1 => ['WMA'], 2 => ['WAV'], 3 => ['OGG'], 4 => ['EAC'],
                    5 => ['DTS'], 6 => ['AAC'], 7 => ['APE'], 8 => ['FLAC'],
                ],
                'b' => [
                    0 => ['CD'], 1 => ['Radio'], 2 => ['Compilation'], 3 => ['DVD'], 4 => ['Other'],
                    5 => ['Vinyl'], 6 => ['Stream'],
                ],
                'c' => [
                    0 => ['Variable'], 1 => ['< 96kbit'], 2 => ['96kbit'], 3 => ['128kbit'], 4 => ['160kbit'],
                    5 => ['192kbit'], 6 => ['256kbit'], 7 => ['320kbit'], 8 => ['Lossless'], 9 => ['Other'],
                ],
                'd' => [
                    0 => ['Blues'], 1 => ['Compilation'], 2 => ['Cabaret'], 3 => ['Dance'], 4 => ['Diverse'],
                    5 => ['Hardcore'], 6 => ['World'], 7 => ['Jazz'], 8 => ['Youth'], 9 => ['Classical'],
                    10 => ['Kleinkunst'], 11 => ['Dutch'], 12 => ['New Age'], 13 => ['Pop'], 14 => ['RnB'],
                    15 => ['Hiphop'], 16 => ['Reggae'], 17 => ['Religious'], 18 => ['Rock'],
                    19 => ['Soundtracks'], 20 => ['Other'], 21 => ['Hardstyle'], 22 => ['Asian'],
                    23 => ['Disco'], 24 => ['Classics'], 25 => ['Metal'], 26 => ['Country'], 27 => ['Dubstep'],
                    28 => ['Nederhop'], 29 => ['DnB'], 30 => ['Electro'], 31 => ['Folk'], 32 => ['Soul'],
                    33 => ['Trance'], 34 => ['Balkan'], 35 => ['Techno'], 36 => ['Ambient'], 37 => ['Latin'],
                    38 => ['Live'],
                ],
                'z' => [0 => 'Album', 1 => 'Liveset', 2 => 'Podcast', 3 => 'Audiobook'],
            ],
            2 => [
                'a' => [
                    0 => ['Windows'], 1 => ['Macintosh'], 2 => ['Linux'], 3 => ['Playstation'],
                    4 => ['Playstation 2'], 5 => ['PSP'], 6 => ['Xbox'], 7 => ['Xbox 360'],
                    8 => ['Gameboy Advance'], 9 => ['Gamecube'], 10 => ['Nintendo DS'], 11 => ['Nintento Wii'],
                    12 => ['Playstation 3'], 13 => ['Windows Phone'], 14 => ['iOS'], 15 => ['Android'],
                    16 => ['Nintendo 3DS'], 17 => ['Playstation 4'], 18 => ['XBox 1'],
                ],
                'b' => [
                    0 => ['ISO'], 1 => ['Rip'], 2 => ['Retail'], 3 => ['DLC'], 4 => [''], 5 => ['Patch'],
                    6 => ['Crack'],
                ],
                'c' => [
                    0 => ['Action'], 1 => ['Adventure'], 2 => ['Strategy'], 3 => ['Roleplaying'],
                    4 => ['Simulation'], 5 => ['Race'], 6 => ['Flying'], 7 => ['Shooter'], 8 => ['Platform'],
                    9 => ['Sport'], 10 => ['Child/youth'], 11 => ['Puzzle'], 12 => ['Other'],
                    13 => ['Boardgame'], 14 => ['Cards'], 15 => ['Education'], 16 => ['Music'],
                    17 => ['Family'],
                ],
                'z' => ['z' => 'everything'],
            ],
            3 => [
                'a' => [
                    0 => ['Windows'], 1 => ['Macintosh'], 2 => ['Linux'], 3 => ['OS/2'],
                    4 => ['Windows Phone'], 5 => ['Navigation systems'], 6 => ['iOS'], 7 => ['Android'],
                ],
                'b' => [
                    0 => ['Audio'], 1 => ['Video'], 2 => ['Graphics'], 3 => ['CD/DVD Tools'],
                    4 => ['Media players'], 5 => ['Rippers & Encoders'], 6 => ['Plugins'],
                    7 => ['Database tools'], 8 => ['Email software'], 9 => ['Photo'], 10 => ['Screensavers'],
                    11 => ['Skin software'], 12 => ['Drivers'], 13 => ['Browsers'],
                    14 => ['Download managers'], 15 => ['Download'], 16 => ['Usenet software'],
                    17 => ['RSS Readers'], 18 => ['FTP software'], 19 => ['Firewalls'],
                    20 => ['Antivirus software'], 21 => ['Antispyware software'], 22 => ['Optimization software'],
                    23 => ['Security software'], 24 => ['System software'], 25 => ['Other'],
                    26 => ['Educational'], 27 => ['Office'], 28 => ['Internet'], 29 => ['Communication'],
                    30 => ['Development'], 31 => ['Spotnet'],
                ],
                'z' => ['z' => 'everything'],
            ],
        ];
    }
}
