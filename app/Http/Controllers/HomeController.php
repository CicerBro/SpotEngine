<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\SpotSearchCriteria;
use App\Enums\SearchField;
use App\Models\Category;
use App\Models\Spot;
use App\Models\User;
use App\Services\ListingCacheService;
use App\Services\NzbDownloadService;
use App\Services\Search\Contracts\SearchDriver;
use App\Services\SpotEnricher;
use App\Services\SpotImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\AcceptHeader;

class HomeController extends Controller
{
    public function __construct(
        private readonly SpotImageService $imageService,
        private readonly SpotEnricher $enricher,
        private readonly NzbDownloadService $nzbService,
        private readonly ListingCacheService $listingCache,
        private readonly SearchDriver $searchDriver,
    ) {}

    public function index(Request $request): View|JsonResponse
    {
        $criteria = $this->searchCriteria($request);

        /** @var array{spots: CursorPaginator<int, Spot>, spotCount: int|null} $listing */
        $listing = $this->listingCache->remember($request, function () use ($request, $criteria): array {
            $spotCount = $request->expectsJson() ? null : $this->searchDriver->count($criteria);

            return [
                'spots' => $this->searchDriver
                    ->cursorPaginate($criteria)
                    ->withQueryString(),
                'spotCount' => $spotCount,
            ];
        });

        $categoriesByCode = Category::allCached()->keyBy('code');

        if ($request->expectsJson()) {
            $html = view('partials.spots-table', [
                'spots' => $listing['spots'],
                'spotCount' => 0,
                'categoriesByCode' => $categoriesByCode,
            ])->fragment('spot-rows');

            return response()->json([
                'html' => $html,
                'next_url' => $listing['spots']->nextPageUrl(),
                'has_more' => $listing['spots']->hasMorePages(),
                'count' => $listing['spots']->count(),
            ]);
        }

        return view('spots.index', [
            'spots' => $listing['spots'],
            'spotCount' => $listing['spotCount'],
            'categoryTree' => Category::tree(),
            'categoriesByCode' => $categoriesByCode,
            'currentCategory' => $request->cat,
            'search' => $request->q,
            'subcats' => (array) $request->subcat,
        ]);
    }

    public function show(Spot $spot): View
    {
        $this->enricher->enrich($spot);

        $categoriesByCode = Category::allCached()->keyBy('code');

        $subcategoryNames = collect($spot->subcategories ?? [])
            ->mapWithKeys(function (string $code) use ($categoriesByCode, $spot): array {
                $category = $spot->resolveSubcategory($categoriesByCode, $code);

                if ($category instanceof Category) {
                    if ($category->name === '-') {
                        return [];
                    }

                    return [$code => $category->name];
                }

                return [$code => $code];
            });

        $badgeCategory = $spot->resolveBadgeCategory($categoriesByCode);
        if ($badgeCategory instanceof Category) {
            $badgeLabel = $badgeCategory->name;
        } elseif ($spot->category instanceof Category) {
            $badgeLabel = $spot->category->name;
        } else {
            $badgeLabel = $spot->category_code;
        }

        return view('spots.show', [
            'spot' => $spot,
            'category' => $spot->category,
            'subcategoryNames' => $subcategoryNames,
            'rootCategory' => $spot->root_category,
            'badgeLabel' => $badgeLabel,
            'hasBadgeCategory' => $badgeCategory instanceof Category,
            'genreLabel' => $spot->resolveGenreLabel($categoriesByCode),
        ]);
    }

    public function downloadNzb(Request $request, Spot $spot): Response
    {
        $serveGzipped = $this->requestAcceptsGzip($request);
        $nzb = $serveGzipped
            ? $this->nzbService->fetchGzippedNzb($spot)
            : $this->nzbService->fetchNzb($spot);
        $user = $request->user();

        if ($user instanceof User) {
            $user->downloads()->updateOrCreate(
                ['spot_id' => $spot->id],
                ['downloaded_at' => now()],
            );
        }

        $headers = [
            'Content-Type' => 'application/x-nzb',
            'Content-Disposition' => 'attachment; filename="'.$this->nzbService->filename($spot).'"',
            'X-DNZB-Name' => $spot->title,
            'Cache-Control' => 'public, max-age=2592000',
            'Vary' => 'Accept-Encoding',
        ];

        if ($serveGzipped) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return response($nzb, 200, $headers);
    }

    public function downloadImage(Spot $spot): Response
    {
        try {
            $image = $this->imageService->fetch($spot);
        } catch (\Throwable) {
            return $this->placeholderImageResponse('Error');
        }

        if ($image === null) {
            return $this->placeholderImageResponse('No Image');
        }

        return $this->imageResponse($image['data'], $image['content_type']);
    }

    public function categoriesJson(): JsonResponse
    {
        return response()->json(Category::tree());
    }

    private function searchCriteria(Request $request): SpotSearchCriteria
    {
        return new SpotSearchCriteria(
            term: $request->filled('q') ? (string) $request->q : null,
            field: SearchField::fromRequest($request->search_in),
            category: $request->filled('cat') ? (string) $request->cat : null,
            subcategories: array_values(array_filter(
                (array) $request->subcat,
                \is_string(...),
            )),
            perPage: max(10, min(100, $request->integer('per_page', 50))),
            cursor: $request->filled('cursor') ? (string) $request->cursor : null,
        );
    }

    private function imageResponse(string $data, string $contentType): Response
    {
        return response($data, 200, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($data),
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'ETag' => '"'.hash('sha256', $data).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function requestAcceptsGzip(Request $request): bool
    {
        $gzip = AcceptHeader::fromString($request->headers->get('Accept-Encoding'))->get('gzip');

        return $gzip !== null && $gzip->getQuality() > 0.0;
    }

    private function placeholderImageResponse(string $text): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="280" viewBox="0 0 200 280"><rect fill="#1f2937" width="200" height="280"/><text x="100" y="140" text-anchor="middle" fill="#6b7280" font-family="sans-serif" font-size="14">'.htmlspecialchars($text).'</text></svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
