<?php

use App\Services\Search\ExternalIdentifierNormalizer;

test('normalizes explicit provider identifiers without matching adjacent characters', function () {
    $normalizer = new ExternalIdentifierNormalizer;

    expect($normalizer->extract('TT001234567 imdb:1234567 tvdbid-42 TVMAZE:00123 https://themoviedb.org/movie/45-name tmdb:tv:45 tmdbid:45'))
        ->toBe(['imdb:1234567', 'tmdb:45', 'tmdb_movie:45', 'tmdb_tv:45', 'tvdb:42', 'tvmaze:123']);
    expect($normalizer->extract('xtt1234567 tt1234567x tvmaze:123x imdb:0 tt1234567890123456789 __invalid_imdb_identifier__'))
        ->toBe([]);
});

test('preserves longer identifiers and separates providers and movie from television', function () {
    $normalizer = new ExternalIdentifierNormalizer;

    expect($normalizer->extract('tt12345678 https://thetvdb.com/?tab=series&amp;id=12'))
        ->toBe(['imdb:12345678', 'tvdb:12']);
    expect($normalizer->attributes(['imdb:1234567', 'imdb:12345678', 'tmdb_tv:45']))
        ->toBe([
            'imdb_ids' => [1234567, 12345678], 'tvdb_ids' => [], 'tvmaze_ids' => [],
            'tmdb_movie_ids' => [], 'tmdb_tv_ids' => [45], 'tmdb_ids' => [],
        ]);
});
