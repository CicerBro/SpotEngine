<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SearchField;
use App\Models\Category;
use App\Models\Spot;
use App\Services\ListingCacheService;
use App\Services\NzbDownloadService;
use App\Services\SpotEnricher;
use App\Services\SpotImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(
        private readonly SpotImageService $imageService,
        private readonly SpotEnricher $enricher,
        private readonly NzbDownloadService $nzbService,
        private readonly ListingCacheService $listingCache,
    ) {}

    public function index(Request $request): View
    {
        $spots = $this->listingCache->remember($request, function () use ($request) {
            return Spot::query()
                ->select(['id', 'title', 'poster', 'file_size', 'spot_posted_at', 'category_code', 'subcategories', 'nzb_segments'])
                ->with('category:code,name,slug')
                ->when($request->filled('cat'), fn ($q) => $q->inCategory($request->cat))
                ->when($request->filled('subcat'), fn ($q) => $q->withSubcategory((array) $request->subcat))
                ->when($request->filled('q'), fn ($q) => $q->search($request->q, SearchField::fromRequest($request->search_in)))
                ->latestFirst()
                ->paginate(max(10, min(100, $request->integer('per_page', 50))))
                ->withQueryString();
        });

        return view('spots.index', [
            'spots' => $spots,
            'categoryTree' => Category::tree(),
            'categoriesByCode' => Category::allCached()->keyBy('code'),
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
            'hasBadgeCategory' => $badgeCategory !== null,
            'genreLabel' => $spot->resolveGenreLabel($categoriesByCode),
        ]);
    }

    public function getNzb(Spot $spot): Response
    {
        $nzb = $this->nzbService->fetchNzb($spot);

        return response($nzb, 200, [
            'Content-Type' => 'application/x-nzb',
            'Content-Disposition' => 'attachment; filename="'.$this->nzbService->filename($spot).'"',
            'X-DNZB-Name' => $spot->title,
            'Cache-Control' => 'public, max-age=2592000',
        ]);
    }

    public function getImage(Spot $spot): Response
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
