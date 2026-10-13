<?php

declare(strict_types=1);

use App\Models\Category;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->app->instance('request', Request::create('/', 'GET', [
        'cat' => '01',
        'subcat' => ['01z03', '01a15', '01a01'],
        'q' => 'sample',
        'new' => '1',
        'search_in' => 'both',
        'per_page' => '50',
        'cursor' => 'stale-cursor',
        'page' => '3',
    ]));
});

function composableSidebarData(): array
{
    $image = Category::factory()->make(['code' => '01', 'name' => 'Image', 'slug' => 'image']);
    $audio = Category::factory()->make(['code' => '02', 'name' => 'Audio', 'slug' => 'audio']);
    $mp3 = Category::factory()->make(['code' => '02a01', 'name' => 'MP3', 'type' => 'format']);
    $choices = collect([
        Category::factory()->make(['code' => '01z03', 'name' => 'Erotica', 'type' => 'type']),
        Category::factory()->make(['code' => '01a15', 'name' => 'UHD', 'type' => 'format']),
        Category::factory()->make(['code' => '01a01', 'name' => 'WMV', 'type' => 'format']),
        Category::factory()->make(['code' => '01p01', 'name' => 'Windows', 'type' => 'platform']),
        Category::factory()->make(['code' => '01b01', 'name' => 'Retail', 'type' => 'source']),
        Category::factory()->make(['code' => '01d01', 'name' => 'English', 'type' => 'language']),
        Category::factory()->make(['code' => '01f01', 'name' => 'High', 'type' => 'bitrate']),
        Category::factory()->make(['code' => '01c01', 'name' => 'Action', 'type' => 'genre']),
    ]);

    return [
        'categoryTree' => [
            '01' => ['category' => $image, 'subcategories' => $choices->groupBy('type')->all()],
            '02' => ['category' => $audio, 'subcategories' => ['format' => collect([$mp3])]],
        ],
        'categoriesByCode' => $choices->concat([$image, $audio, $mp3])->keyBy('code'),
    ];
}

function composableSidebarDom(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML($html);

    return new DOMXPath($document);
}

function composableSidebarLinkQuery(DOMXPath $dom, string $text): array
{
    $link = null;
    foreach ($dom->query('//a') as $candidate) {
        if (trim(preg_replace('/\s+/', ' ', $candidate->textContent)) === $text) {
            $link = $candidate;
            break;
        }
    }
    expect($link)->not->toBeNull();
    parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY) ?? '', $query);

    return $query;
}

test('sidebar offers named checkbox groups and preserves selections across groups', function () {
    $html = $this->view('partials.sidebar', composableSidebarData());
    $dom = composableSidebarDom((string) $html);

    $html->assertSee('Match any choice within a group. Each selected group must match.');
    foreach (['Type', 'Format', 'Platform', 'Source', 'Language', 'Bitrate', 'Genre'] as $label) {
        expect($dom->query('//details[@id="sidebar-root-01"]//fieldset/legend[normalize-space(.)="' . $label . '"]')->length)->toBe(1);
    }
    foreach (['01z03', '01a15', '01a01'] as $code) {
        expect($dom->query('//form[@id="sidebar-filters"]//input[@type="checkbox" and @name="subcat[]" and @value="' . $code . '" and @checked]')->length)->toBe(1);
    }
    expect($dom->query('//form[@id="sidebar-filters"]//input[@type="checkbox" and @checked]')->length)->toBe(3);
    expect($dom->query('//button[@type="submit" and @form="sidebar-filters"]')->length)->toBe(1);
    expect($dom->query('//button[@type="submit"]')->length)->toBe(1);
    expect($dom->query('//form[@method="GET"]')->length)->toBe(1);
});

test('sidebar forms preserve search preferences and omit pagination when filters change', function () {
    $dom = composableSidebarDom((string) $this->view('partials.sidebar', composableSidebarData()));

    foreach (['cat' => '01', 'q' => 'sample', 'new' => '1', 'search_in' => 'both', 'per_page' => '50'] as $name => $value) {
        expect($dom->query('//form[@id="sidebar-filters"]//input[@type="hidden" and @name="' . $name . '" and @value="' . $value . '"]')->length)->toBe(1);
    }
    expect($dom->query('//form[@id="sidebar-filters"]//input[@name="cursor" or @name="page"]')->length)->toBe(0);
    expect(composableSidebarLinkQuery($dom, 'Audio'))->toBe([
        'q' => 'sample', 'new' => '1', 'search_in' => 'both', 'per_page' => '50', 'cat' => '02',
    ]);
    expect(composableSidebarLinkQuery($dom, 'Reset'))->toBe([]);
});

test('active chips remove one selection while preserving other facets and search state', function () {
    $dom = composableSidebarDom((string) $this->view('partials.spot-filters-active', composableSidebarData()));

    $query = composableSidebarLinkQuery($dom, 'UHD');
    expect($query['subcat'])->toBe(['01z03', '01a01']);
    expect($query)->toMatchArray(['cat' => '01', 'q' => 'sample', 'new' => '1', 'search_in' => 'both', 'per_page' => '50']);
    foreach (['UHD', 'Erotica', 'WMV', 'Image', 'New', '"sample"'] as $label) {
        expect(composableSidebarLinkQuery($dom, $label))->not->toHaveKeys(['cursor', 'page']);
    }
    expect(composableSidebarLinkQuery($dom, 'Image'))->not->toHaveKeys(['cat', 'subcat']);
});

test('search submission retains all selected facets and discards pagination', function () {
    $dom = composableSidebarDom((string) $this->view('partials.navbar'));

    foreach (['01z03', '01a15', '01a01'] as $code) {
        expect($dom->query('//form[@role="search"]//input[@name="subcat[]" and @value="' . $code . '"]')->length)->toBe(1);
    }
    expect($dom->query('//form[@role="search"]//input[@name="new" and @value="1"]')->length)->toBe(1);
    expect($dom->query('//form[@role="search"]//input[@name="cursor" or @name="page"]')->length)->toBe(0);
});

test('sidebar opens the selected root or the first root by default', function (?string $category, string $openRoot) {
    $this->app->instance('request', Request::create('/', 'GET', $category ? ['cat' => $category] : []));

    $dom = composableSidebarDom((string) $this->view('partials.sidebar', composableSidebarData()));

    expect($dom->query('//details[starts-with(@id, "sidebar-root-") and @open]')->length)->toBe(1);
    expect($dom->query('//details[@id="sidebar-root-' . $openRoot . '" and @open]')->length)->toBe(1);
})->with([
    'no category defaults to Image' => [null, '01'],
    'selected Image' => ['01', '01'],
    'selected Audio' => ['02', '02'],
]);

test('secondary groups open only when they contain a selected checkbox', function (?string $selectedCode, ?string $openGroup) {
    $this->app->instance('request', Request::create('/', 'GET', [
        'cat' => '01',
        'subcat' => array_filter(['01z03', '01a15', $selectedCode]),
    ]));

    $dom = composableSidebarDom((string) $this->view('partials.sidebar', composableSidebarData()));

    expect($dom->query('//details[@id="sidebar-root-01"]/div/details')->length)->toBe(4);
    expect($dom->query('//details[@id="sidebar-root-01"]/div/details[@open]')->length)->toBe($openGroup ? 1 : 0);
    if ($openGroup) {
        expect($dom->query('//details[@id="sidebar-root-01"]/div/details[@open and summary="' . $openGroup . '"]//input[@type="checkbox" and @name="subcat[]" and @value="' . $selectedCode . '" and @checked]')->length)->toBe(1);
    }
    expect($dom->query('//details[@id="sidebar-root-01"]/div/fieldset')->length)->toBe(3);
})->with([
    'no secondary selections' => [null, null],
    'selected Source' => ['01b01', 'Source'],
    'selected Language' => ['01d01', 'Language'],
    'selected Bitrate' => ['01f01', 'Bitrate'],
    'selected Genre' => ['01c01', 'Genre'],
]);

test('sidebar stages compatible choices in one form and disables other root choices', function (?string $category, string $activeRoot, string $enabledChoice, string $disabledChoice) {
    $this->app->instance('request', Request::create('/', 'GET', $category ? ['cat' => $category] : []));

    $dom = composableSidebarDom((string) $this->view('partials.sidebar', composableSidebarData()));

    expect($dom->query('//form[@id="sidebar-filters"]//input[@type="hidden" and @name="cat" and @value="' . $activeRoot . '"]')->length)->toBe(1);
    expect($dom->query('//input[@name="subcat[]" and @value="' . $enabledChoice . '" and not(@disabled)]')->length)->toBe(1);
    expect($dom->query('//input[@name="subcat[]" and @value="' . $disabledChoice . '" and @disabled]')->length)->toBe(1);
    expect($dom->query('//input[@name="subcat[]" and @onchange]')->length)->toBe(0);
})->with([
    'default Image accepts UHD and disables MP3' => [null, '01', '01a15', '02a01'],
    'selected Image accepts UHD and disables MP3' => ['01', '01', '01a15', '02a01'],
    'selected Audio accepts MP3 and disables UHD' => ['02', '02', '02a01', '01a15'],
]);
