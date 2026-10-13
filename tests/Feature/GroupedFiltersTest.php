<?php

declare(strict_types=1);

use App\Data\SpotSearchCriteria;
use App\Models\Spot;
use App\Models\User;
use App\Services\Search\Drivers\DatabaseSearchDriver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('erotica and UHD filters require both facets for rows', function () {
    $user = User::factory()->create();
    $matching = Spot::factory()->inCategory('01')->create(['subcategories' => ['01z03', '01a15']]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01z00', '01a15']]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01z03', '01a09']]);
    Spot::factory()->inCategory('02')->create(['subcategories' => ['01z03', '01a15']]);

    $response = $this->actingAs($user)->get('/?' . http_build_query([
        'cat' => '01', 'subcat' => ['01z03', '01a15'],
    ]));

    expect($response->viewData('spotCount'))->toBeNull();
    expect($response->viewData('spots')->pluck('id')->all())->toBe([$matching->id]);
});

test('formats are alternatives while the erotica type remains required', function () {
    $user = User::factory()->create();
    $postedAt = now()->startOfSecond();
    $matching = Spot::factory()->inCategory('01')->count(2)->sequence(
        ['subcategories' => ['01z03', '01a15']],
        ['subcategories' => ['01z03', '01a09']],
    )->create(['spot_posted_at' => $postedAt]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01z03', '01a00']]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01z00', '01a09', '01a15']]);

    $response = $this->actingAs($user)->get('/?' . http_build_query([
        'cat' => '01', 'subcat' => ['01z03', '01a15', '01a09'],
    ]));

    expect($response->viewData('spotCount'))->toBeNull();
    expect($response->viewData('spots')->pluck('id')->all())->toBe($matching->pluck('id')->reverse()->values()->all());
});

test('genre alternatives remain narrowed by source format text and unread state', function () {
    $this->travelTo('2026-10-13 12:00:00');
    $user = User::factory()->create(['spots_read_until' => now()->subHour()]);
    $matching = Spot::factory()->inCategory('01')->count(2)->sequence(
        ['subcategories' => ['01d00', '01b11', '01a15']],
        ['subcategories' => ['01d04', '01b11', '01a15']],
    )->create(['title' => 'Aurora release', 'spot_posted_at' => now()]);
    Spot::factory()->inCategory('01')->count(5)->state(['title' => 'Aurora release', 'spot_posted_at' => now()])->sequence(
        ['subcategories' => ['01d07', '01b11', '01a15']],
        ['subcategories' => ['01d00', '01b03', '01a15']],
        ['subcategories' => ['01d04', '01b11', '01a09']],
        ['subcategories' => ['01d04', '01b11', '01a15'], 'title' => 'Unrelated release'],
        ['subcategories' => ['01d00', '01b11', '01a15'], 'spot_posted_at' => now()->subHours(2)],
    )->create();

    $response = $this->actingAs($user)->get('/?' . http_build_query([
        'cat' => '01', 'subcat' => ['01d00', '01d04', '01b11', '01a15'], 'q' => 'Aurora', 'new' => 1,
    ]));

    expect($response->viewData('spotCount'))->toBeNull();
    expect($response->viewData('spots')->pluck('id')->all())->toBe($matching->pluck('id')->reverse()->values()->all());
});

test('malformed and mismatched category filters return 422', function (array $filters, string $error) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/?' . http_build_query($filters));

    $response->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'root must be scalar' => [['cat' => ['01']], 'cat'],
    'root must be known' => [['cat' => '05'], 'cat'],
    'nested subcategory' => [['subcat' => [['01a15']]], 'subcat.0'],
    'malformed subcategory' => [['subcat' => ['01a150']], 'subcat.0'],
    'mixed roots without a root selection' => [['subcat' => ['01a15', '02a00']], 'subcat'],
    'subcategory from different root' => [['cat' => '01', 'subcat' => ['02a00']], 'subcat.0'],
    'too many selections' => [['subcat' => array_fill(0, 65, '01a15')], 'subcat'],
]);

test('legacy scalar subcategory still filters a listing', function () {
    $user = User::factory()->create();
    $matching = Spot::factory()->inCategory('01')->create(['subcategories' => ['01a15']]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01a09']]);

    $response = $this->actingAs($user)->get('/?cat=01&subcat=01a15');

    expect($response->viewData('spotCount'))->toBeNull();
    expect($response->viewData('spots')->pluck('id')->all())->toBe([$matching->id]);
});

test('unknown canonical taxonomy selections return an empty listing', function () {
    $user = User::factory()->create();
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01a15']]);

    $response = $this->actingAs($user)->get('/?cat=01&subcat[]=01a99');

    expect($response->viewData('spotCount'))->toBeNull();
    expect($response->viewData('spots')->items())->toBe([]);
});

test('postgres combines singleton facets in one indexable containment predicate', function () {
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01z03', '01a15']]);
    Spot::factory()->inCategory('01')->create(['subcategories' => ['01z00', '01a15']]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query;
    });

    $count = (new DatabaseSearchDriver)->count(new SpotSearchCriteria(
        category: '01',
        subcategories: ['01z03', '01a15'],
    ));

    expect($count)->toBe(1);
    expect($queries)->toHaveCount(1);
    expect(substr_count($queries[0]->sql, '@>'))->toBe(1);
    expect($queries[0]->bindings)->toBe(['01', '["01a15","01z03"]']);
});

test('subcategory filtered HTML shows loaded rows without querying a total', function () {
    $user = User::factory()->create();
    Spot::factory()->inCategory('01')->count(2)->create(['subcategories' => ['01z03', '01a15']]);
    $user->unreadSpotCount();
    $countQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$countQueries): void {
        if (str_contains($query->sql, '"spots"') && preg_match('/count\s*\(/i', $query->sql) === 1) {
            $countQueries[] = $query->sql;
        }
    });

    $response = $this->actingAs($user)->get('/?' . http_build_query([
        'cat' => '01', 'subcat' => ['01z03', '01a15'],
    ]));

    $response->assertSee('2 spots loaded');
    expect($response->viewData('spotCount'))->toBeNull();
    expect($countQueries)->toBe([]);
});

test('grouped filters persist through cursor pages without repeating equal timestamp rows', function () {
    $user = User::factory()->create();
    $postedAt = now()->startOfSecond();
    $matching = Spot::factory()->inCategory('01')->count(12)->sequence(fn ($sequence): array => [
        'title' => sprintf('Grouped cursor row %02d', $sequence->index),
        'subcategories' => ['01z03', $sequence->index % 2 === 0 ? '01a15' : '01a09'],
    ])->create(['spot_posted_at' => $postedAt]);
    Spot::factory()->inCategory('01')->count(2)->sequence(
        ['subcategories' => ['01z00', '01a15']],
        ['subcategories' => ['01z03', '01a00']],
    )->create(['title' => 'Excluded grouped cursor row', 'spot_posted_at' => $postedAt]);
    $filters = ['cat' => '01', 'subcat' => ['01a09', '01a15', '01z03'], 'per_page' => 10];

    $response = $this->actingAs($user)->get('/?' . http_build_query($filters));
    $nextUrl = $response->viewData('spots')->nextPageUrl();
    expect($nextUrl)->not->toBeNull();
    parse_str(parse_url($nextUrl, PHP_URL_QUERY), $nextFilters);
    $nextResponse = $this->actingAs($user)->getJson($nextUrl);

    expect($response->viewData('spots')->pluck('id')->all())->toBe($matching->pluck('id')->reverse()->take(10)->values()->all());
    expect($nextFilters['cat'])->toBe('01');
    expect($nextFilters['subcat'])->toBe(['01a09', '01a15', '01z03']);
    $nextResponse->assertJsonPath('count', 2)->assertJsonPath('has_more', false)->assertJsonPath('next_url', null);
    expect($nextResponse->json('html'))->toContain('Grouped cursor row 00')->toContain('Grouped cursor row 01')
        ->not->toContain('Grouped cursor row 02')->not->toContain('Grouped cursor row 11')
        ->not->toContain('Excluded grouped cursor row');
});

test('subcategory selection infers its root category when the root is omitted', function () {
    $user = User::factory()->create();
    $matching = Spot::factory()->inCategory('01')->create(['subcategories' => ['01z03', '01a15']]);
    Spot::factory()->inCategory('02')->create(['subcategories' => ['01z03', '01a15']]);

    $response = $this->actingAs($user)->get('/?' . http_build_query([
        'subcat' => ['01z03', '01a15'],
    ]));

    $response->assertViewHas('currentCategory', '01');
    expect($response->viewData('spots')->pluck('id')->all())->toBe([$matching->id]);
});
