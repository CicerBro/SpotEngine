<?php

declare(strict_types=1);

use App\Models\Spot;
use App\Models\User;
use App\Services\NzbDownloadService;
use App\Services\Search\Contracts\SearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\ManticoreDocumentMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('API caps returns XML without API key', function () {
    $response = $this->get(route('api', ['t' => 'caps']));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    $response->assertSee('SpotEngine');
    $response->assertSee('searching');
    $response->assertSee('categories');
});

test('API search requires API key', function () {
    $response = $this->get(route('api', ['t' => 'search']));

    $response->assertStatus(401);
    $response->assertSee('API key is required');
});

test('API search with incorrect API key returns error', function () {
    $response = $this->get(route('api', ['t' => 'search', 'apikey' => 'wrongkey']));

    $response->assertStatus(401);
    $response->assertSee('Incorrect API key');
});

test('API search with valid key returns RSS', function () {
    $user = User::factory()->create();
    Spot::factory()->count(2)->create();

    $response = $this->get(route('api', [
        't' => 'search',
        'apikey' => $user->api_token,
        'q' => 'test',
    ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/xml; charset=UTF-8');
});

test('API search reports total and offset for pagination', function () {
    $user = User::factory()->create();
    Spot::factory()->count(5)->create(['title' => 'unique pagination test']);

    $response = $this->get(route('api', [
        't' => 'search',
        'apikey' => $user->api_token,
        'q' => 'unique pagination test',
        'limit' => 2,
        'offset' => 2,
    ]));

    $response->assertSuccessful();
    $response->assertSee('offset="2"', false);
    $response->assertSee('total="5"', false);
});

test('API search honors offsets that are not page boundaries', function () {
    $user = User::factory()->create();
    $newest = Spot::factory()->create(['title' => 'Offset result newest', 'spot_posted_at' => now()]);
    $second = Spot::factory()->create(['title' => 'Offset result second', 'spot_posted_at' => now()->subMinute()]);
    $third = Spot::factory()->create(['title' => 'Offset result third', 'spot_posted_at' => now()->subMinutes(2)]);
    $oldest = Spot::factory()->create(['title' => 'Offset result oldest', 'spot_posted_at' => now()->subMinutes(3)]);

    $response = $this->get(route('api', [
        't' => 'search',
        'apikey' => $user->api_token,
        'limit' => 2,
        'offset' => 1,
    ]));

    $response->assertSuccessful();
    $response->assertSee('offset="1"', false);
    $response->assertSee($second->title);
    $response->assertSee($third->title);
    $response->assertDontSee($newest->title);
    $response->assertDontSee($oldest->title);
});

test('API tvsearch expands season and episode queries', function () {
    $user = User::factory()->create();
    $match = Spot::factory()->inCategory('01')->create([
        'title' => 'Compatibility Show S01E02',
        'subcategories' => ['01z01'],
    ]);
    $other = Spot::factory()->inCategory('01')->create([
        'title' => 'Compatibility Show S01E03',
        'subcategories' => ['01z01'],
    ]);
    $movie = Spot::factory()->inCategory('01')->create([
        'title' => 'Compatibility Show S01E02 movie',
        'subcategories' => ['01z00'],
    ]);

    $response = $this->get(route('api', [
        't' => 'tvsearch',
        'apikey' => $user->api_token,
        'q' => 'Compatibility Show',
        'season' => '1',
        'ep' => '2',
    ]));

    $response->assertSuccessful();
    $response->assertSee($match->title);
    $response->assertDontSee($other->title);
    $response->assertDontSee($movie->title);
});

test('API tvsearch expands localized season queries', function () {
    $user = User::factory()->create();
    $matches = [];

    foreach ([
        'Localized Season Show S5',
        'Localized Season Show Season 5',
        'Localized Season Show Seizoen 5',
        'Localized Season Show Staffel 5',
        'Localized Season Show Saison 5',
        'Localized Season Show Temporada 5',
    ] as $title) {
        $matches[] = Spot::factory()->inCategory('01')->create([
            'title' => $title,
            'subcategories' => ['01z01'],
        ]);
    }

    $response = $this->get(route('api', [
        't' => 'tvsearch',
        'apikey' => $user->api_token,
        'q' => 'Localized Season Show',
        'season' => '5',
    ]));

    $response->assertSuccessful();

    foreach ($matches as $match) {
        $response->assertSee($match->title);
    }
});

test('API tvsearch expands localized season and episode queries', function () {
    $user = User::factory()->create();
    $matches = [];

    foreach ([
        'Localized Episode Show S1E2',
        'Localized Episode Show Season 1 Episode 2',
        'Localized Episode Show Seizoen 1 Aflevering 2',
        'Localized Episode Show Staffel 1 Folge 2',
        'Localized Episode Show Saison 1 Épisode 2',
        'Localized Episode Show Saison 1 Episode 2',
        'Localized Episode Show Temporada 1 Episodio 2',
        'Localized Episode Show Temporada 1 Episódio 2',
    ] as $title) {
        $matches[] = Spot::factory()->inCategory('01')->create([
            'title' => $title,
            'subcategories' => ['01z01'],
        ]);
    }

    $response = $this->get(route('api', [
        't' => 'tvsearch',
        'apikey' => $user->api_token,
        'q' => 'Localized Episode Show',
        'season' => '1',
        'ep' => '2',
    ]));

    $response->assertSuccessful();

    foreach ($matches as $match) {
        $response->assertSee($match->title);
    }
});

test('API tvsearch supports its advertised TV identifiers', function () {
    $user = User::factory()->create();
    $tvmaze = Spot::factory()->inCategory('01')->create([
        'title' => 'TVmaze identifier match',
        'website' => 'https://www.tvmaze.com/shows/12345/example',
        'subcategories' => ['01z01'],
    ]);
    $tvdb = Spot::factory()->inCategory('01')->create([
        'title' => 'TVDB identifier match',
        'description' => 'Imported metadata: tvdbid:67890',
        'subcategories' => ['01z01'],
    ]);
    $tmdb = Spot::factory()->inCategory('01')->create([
        'title' => 'TMDB identifier match',
        'website' => 'https://www.themoviedb.org/tv/24680/example',
        'subcategories' => ['01z01'],
    ]);

    $this->get(route('api', [
        't' => 'tvsearch',
        'apikey' => $user->api_token,
        'tvmazeid' => '12345',
    ]))
        ->assertSuccessful()
        ->assertSee($tvmaze->title)
        ->assertDontSee($tvdb->title)
        ->assertDontSee($tmdb->title);

    $this->get(route('api', [
        't' => 'tvsearch',
        'apikey' => $user->api_token,
        'tvdbid' => '67890',
    ]))
        ->assertSuccessful()
        ->assertSee($tvdb->title)
        ->assertDontSee($tvmaze->title)
        ->assertDontSee($tmdb->title);

    $this->get(route('api', [
        't' => 'tvsearch',
        'apikey' => $user->api_token,
        'tmdbid' => '24680',
    ]))
        ->assertSuccessful()
        ->assertSee($tmdb->title)
        ->assertDontSee($tvmaze->title)
        ->assertDontSee($tvdb->title);
});

test('API movie search supports imdbid and tmdbid metadata', function () {
    $user = User::factory()->create();
    $imdbMatch = Spot::factory()->inCategory('01')->create([
        'title' => 'IMDb identifier match',
        'website' => 'https://www.imdb.com/title/tt1234567/',
        'subcategories' => ['01z00'],
    ]);
    $tmdbMatch = Spot::factory()->inCategory('01')->create([
        'title' => 'TMDB identifier match',
        'website' => 'https://www.themoviedb.org/movie/9876543/example',
        'subcategories' => ['01z00'],
    ]);
    $other = Spot::factory()->inCategory('01')->create([
        'title' => 'Other movie',
        'subcategories' => ['01z00'],
    ]);
    $series = Spot::factory()->inCategory('01')->create([
        'title' => 'IMDb identifier series',
        'website' => 'https://www.imdb.com/title/tt1234567/',
        'subcategories' => ['01z01'],
    ]);

    $this->get(route('api', [
        't' => 'movie',
        'apikey' => $user->api_token,
        'imdbid' => '1234567',
    ]))
        ->assertSuccessful()
        ->assertSee($imdbMatch->title)
        ->assertDontSee($other->title)
        ->assertDontSee($series->title);

    $this->get(route('api', [
        't' => 'movie',
        'apikey' => $user->api_token,
        'tmdbid' => '9876543',
    ]))
        ->assertSuccessful()
        ->assertSee($tmdbMatch->title)
        ->assertDontSee($imdbMatch->title)
        ->assertDontSee($other->title)
        ->assertDontSee($series->title);
});

test('API search tvsearch and movie use the manticore query contract', function () {
    $user = User::factory()->create();
    $general = Spot::factory()->create([
        'title' => 'General Manticore result',
        'description' => 'General serialized description',
        'category_code' => '02',
    ]);
    $series = Spot::factory()->create([
        'title' => 'Manticore Show S01E02',
        'website' => 'https://www.tvmaze.com/shows/12345/example',
        'category_code' => '01',
        'subcategories' => ['01z01'],
    ]);
    $movie = Spot::factory()->create([
        'title' => 'Manticore movie result',
        'website' => 'https://www.imdb.com/title/tt1234567/',
        'category_code' => '01',
        'subcategories' => ['01z00'],
    ]);
    $subcategory = new ManticoreDocumentMapper;

    Http::fake(function (Request $request) use ($general, $series, $movie, $subcategory) {
        $payload = $request->data();
        $query = $payload['query'];
        $must = $query['bool']['must'];
        $id = match (true) {
            in_array(['equals' => ['category' => 2]], $must, true) => $general->id,
            in_array(['in' => ['subcategories' => [$subcategory->subcategory('01z01')]]], $must, true) => $series->id,
            default => $movie->id,
        };

        expect($payload['sort'])->toBe([
            ['posted_at' => 'desc'],
            ['id' => 'desc'],
        ]);

        return Http::response([
            'hits' => [
                'total' => 1,
                'total_relation' => 'eq',
                'hits' => [['_id' => $id]],
            ],
        ]);
    });
    $this->app->instance(SearchDriver::class, new ManticoreSearchDriver(
        host: 'manticore.test',
        port: 9308,
        index: 'spots',
        documentMapper: $subcategory,
    ));

    $this->get(route('api', [
        't' => 'search',
        'apikey' => $user->api_token,
        'q' => 'General Manticore',
        'cat' => '3000',
        'limit' => 1,
        'offset' => 3,
    ]))
        ->assertSuccessful()
        ->assertSee($general->title)
        ->assertSee('General serialized description')
        ->assertSee('offset="3"', false)
        ->assertSee('total="1"', false);

    $this->get(route('api', [
        't' => 'tvsearch',
        'apikey' => $user->api_token,
        'q' => 'Manticore Show',
        'season' => '1',
        'ep' => '2',
        'tvmazeid' => '12345',
    ]))
        ->assertSuccessful()
        ->assertSee($series->title);

    $this->get(route('api', [
        't' => 'movie',
        'apikey' => $user->api_token,
        'q' => 'Manticore movie',
        'imdbid' => '1234567',
    ]))
        ->assertSuccessful()
        ->assertSee($movie->title);

    Http::assertSentCount(3);
});

test('API get returns an NZB and records the user download', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['title' => 'Tracked API download']);
    $nzbService = Mockery::mock(NzbDownloadService::class);
    $nzbService->shouldReceive('fetchNzb')->once()->withArgs(fn (Spot $candidate) => $candidate->is($spot))->andReturn('<nzb/>');
    $nzbService->shouldReceive('filename')->once()->withArgs(fn (Spot $candidate) => $candidate->is($spot))->andReturn('tracked.nzb');
    $this->app->instance(NzbDownloadService::class, $nzbService);

    $response = $this->get(route('api', [
        't' => 'get',
        'id' => $spot->id,
        'apikey' => $user->api_token,
    ]));

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'application/x-nzb');
    $response->assertSee('<nzb/>', false);
    $this->assertDatabaseHas('user_downloads', [
        'user_id' => $user->id,
        'spot_id' => $spot->id,
    ]);
});

test('API get serves gzip when accepted', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create(['title' => 'Tracked gzipped API download']);
    $plainNzb = '<nzb><file subject="api"/></nzb>';
    $gzippedNzb = gzencode($plainNzb, 8);

    if ($gzippedNzb === false) {
        throw new RuntimeException('Unable to create gzipped NZB test fixture.');
    }

    $nzbService = Mockery::mock(NzbDownloadService::class);
    $nzbService->shouldReceive('fetchGzippedNzb')
        ->once()
        ->withArgs(fn (Spot $candidate) => $candidate->is($spot))
        ->andReturn($gzippedNzb);
    $nzbService->shouldNotReceive('fetchNzb');
    $nzbService->shouldReceive('filename')
        ->once()
        ->withArgs(fn (Spot $candidate) => $candidate->is($spot))
        ->andReturn('tracked.nzb');
    $this->app->instance(NzbDownloadService::class, $nzbService);

    $response = $this->get(route('api', [
        't' => 'get',
        'id' => $spot->id,
        'apikey' => $user->api_token,
    ]), [
        'Accept-Encoding' => 'gzip',
    ]);

    $response->assertSuccessful();
    $response->assertHeader('Content-Encoding', 'gzip');
    $response->assertHeader('Vary', 'Accept-Encoding');
    expect($response->getContent())->toBe($gzippedNzb)
        ->and(gzdecode((string) $response->getContent()))->toBe($plainNzb);
    $this->assertDatabaseHas('user_downloads', [
        'user_id' => $user->id,
        'spot_id' => $spot->id,
    ]);
});

test('API requests are rate limited per user', function () {
    config()->set('spotengine.newznab.rate_limit_per_minute', 2);
    $user = User::factory()->create();
    $parameters = ['t' => 'search', 'apikey' => $user->api_token];

    $this->get(route('api', $parameters))->assertSuccessful();
    $this->get(route('api', $parameters))->assertSuccessful();
    $this->get(route('api', $parameters))
        ->assertTooManyRequests()
        ->assertSee('API rate limit exceeded');
});

test('API details requires API key', function () {
    $spot = Spot::factory()->create();

    $response = $this->get(route('api', [
        't' => 'details',
        'id' => $spot->id,
    ]));

    $response->assertStatus(401);
});

test('API details with valid key returns spot in RSS', function () {
    $user = User::factory()->create();
    $spot = Spot::factory()->create();

    $response = $this->get(route('api', [
        't' => 'details',
        'id' => $spot->id,
        'apikey' => $user->api_token,
    ]));

    $response->assertSuccessful();
    $response->assertSee($spot->title);
});

test('API unknown action returns 202 error', function () {
    $response = $this->get(route('api', ['t' => 'unknown']));

    $response->assertStatus(400);
    $response->assertSee('No such function');
});

test('production API exceptions do not expose internal messages', function () {
    $this->app['env'] = 'production';
    Route::get('/api/internal-failure', fn () => throw new RuntimeException('database password leaked'));

    $response = $this->get('/api/internal-failure');

    $response->assertInternalServerError();
    $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');
    $response->assertSee('Internal server error');
    $response->assertDontSee('database password leaked');
});
