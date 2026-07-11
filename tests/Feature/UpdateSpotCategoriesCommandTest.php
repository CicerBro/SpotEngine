<?php

declare(strict_types=1);

use App\Data\SpotwebCategories;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function spotwebCategoriesFeatureFixture(): string
{
    return <<<'PHP'
<?php

class SpotCategories
{
    public static $_head_categories = [
        0 => 'Image',
        1 => 'Sound',
        2 => 'Games',
        3 => 'Applications',
    ];

    public static $_subcat_descriptions = [
        0 => ['a' => 'Format', 'z' => 'Type'],
        1 => ['z' => 'Type'],
        2 => ['z' => 'Type'],
        3 => ['z' => 'Type'],
    ];

    public static $_categories = [
        0 => [
            'a' => [
                0 => ['DivX', ['z0'], ['z0']],
            ],
            'z' => [
                0 => 'Movie',
            ],
        ],
        1 => [],
        2 => [
            'z' => [
                'z' => 'everything',
            ],
        ],
        3 => [],
    ];
}
PHP;
}

function fakeSpotwebCategoriesResponse(?string $contents = null, string $url = SpotwebCategories::SOURCE_URL): void
{
    Http::preventStrayRequests();
    Http::fake([
        $url => Http::response($contents ?? spotwebCategoriesFeatureFixture()),
    ]);
}

test('command updates categories from Spotweb definitions', function () {
    fakeSpotwebCategoriesResponse();

    $this->artisan('spot:categories:update')
        ->assertSuccessful()
        ->expectsOutputToContain('Categories updated. Cache cleared.');

    expect(Category::count())->toBeGreaterThan(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === SpotwebCategories::SOURCE_URL);
});

test('command creates head categories with correct codes and names', function () {
    fakeSpotwebCategoriesResponse();

    $this->artisan('spot:categories:update')->assertSuccessful();

    $heads = Category::whereNull('parent_code')->orderBy('code')->get();

    expect($heads)->toHaveCount(4)
        ->and($heads->pluck('name', 'code')->toArray())->toBe([
            '01' => 'Image',
            '02' => 'Sound',
            '03' => 'Games',
            '04' => 'Applications',
        ]);
});

test('command creates subcategories with parent_code and type', function () {
    fakeSpotwebCategoriesResponse();

    $this->artisan('spot:categories:update')->assertSuccessful();

    $movie = Category::where('code', '01z00')->first();
    expect($movie)->not->toBeNull()
        ->and($movie->parent_code)->toBe('01')
        ->and($movie->name)->toBe('Movie')
        ->and($movie->type)->toBe('type');

    $divx = Category::where('code', '01a00')->first();
    expect($divx)->not->toBeNull()
        ->and($divx->parent_code)->toBe('01')
        ->and($divx->name)->toBe('DivX')
        ->and($divx->type)->toBe('format');
});

test('command is idempotent and updates existing categories', function () {
    fakeSpotwebCategoriesResponse();

    Category::create([
        'code' => '01',
        'parent_code' => null,
        'name' => 'Old Image',
        'slug' => 'old-image',
        'type' => null,
        'sort_order' => 1,
    ]);

    $this->artisan('spot:categories:update')->assertSuccessful();

    $cat = Category::where('code', '01')->first();
    expect($cat->name)->toBe('Image')
        ->and($cat->slug)->toBe('image');
});

test('command can sync from an overridden source url', function () {
    $sourceUrl = 'https://example.test/SpotCategories.php';

    fakeSpotwebCategoriesResponse(url: $sourceUrl);

    $this->artisan('spot:categories:update', ['--source-url' => $sourceUrl])
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === $sourceUrl);
});

test('command fails when Spotweb categories cannot be fetched', function () {
    Http::preventStrayRequests();
    Http::fake([
        SpotwebCategories::SOURCE_URL => Http::response('Not found', 404),
    ]);

    $this->artisan('spot:categories:update')
        ->assertFailed()
        ->expectsOutputToContain('Unable to update categories from Spotweb:');

    expect(Category::count())->toBe(0);
});
