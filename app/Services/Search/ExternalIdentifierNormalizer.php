<?php

declare(strict_types=1);

namespace App\Services\Search;

final class ExternalIdentifierNormalizer
{
    public const array PROVIDERS = ['imdb', 'tvdb', 'tvmaze', 'tmdb_movie', 'tmdb_tv', 'tmdb'];

    /**
     * Extract explicit provider references at ingestion, never during search.
     * Numeric identifiers are limited to 18 digits to fit Manticore multi64.
     *
     * @return list<string>
     */
    public function extract(?string ...$sources): array
    {
        $patterns = [
            'imdb' => '(?:tt|imdb[:/])',
            'tvmaze' => '(?:tvmaze(?:id)?[:\-]|tvmaze\.com/shows/)',
            'tvdb' => '(?:tvdb:(?:series:)?|tvdbid[:\-]|(?:the)?tvdb\.com/series/|(?:the)?tvdb\.com/\?tab=series&(?:amp;)?id=)',
            'tmdb_movie' => '(?:(?:themoviedb|tmdb)\.org/movie/|tmdb:movie:|tmdb_movie:)',
            'tmdb_tv' => '(?:(?:themoviedb|tmdb)\.org/tv/|tmdb:tv:|tmdb_tv:)',
            'tmdb' => '(?:tmdb:|tmdbid[:\-])',
        ];
        $identifiers = [];

        foreach ($sources as $source) {
            foreach ($patterns as $provider => $prefix) {
                preg_match_all('~(?<![a-z0-9_])' . $prefix . '([0-9]{1,18})(?![a-z0-9_])~i', $source ?? '', $matches);

                foreach ($matches[1] as $digits) {
                    $digits = ltrim($digits, '0');

                    if ($digits !== '') {
                        $identifiers[] = $provider . ':' . $digits;
                    }
                }
            }
        }

        $identifiers = array_values(array_unique($identifiers));
        sort($identifiers);

        return $identifiers;
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    public function terms(array $terms): array
    {
        return $this->extract(...$terms);
    }

    /**
     * @param  list<string>  $identifiers
     * @return array<string, list<int>>
     */
    public function attributes(array $identifiers): array
    {
        $attributes = array_fill_keys(array_map(static fn (string $provider): string => $provider . '_ids', self::PROVIDERS), []);

        foreach ($identifiers as $identifier) {
            [$provider, $digits] = explode(':', $identifier, 2);
            $attributes[$provider . '_ids'][] = (int) $digits;
        }

        return $attributes;
    }
}
