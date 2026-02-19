<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SearchField;
use App\Models\Category;
use App\Models\Spot;
use App\Services\Nntp\NntpService;
use App\Services\Nntp\NzbGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HomeController extends Controller
{
    public function __construct(private readonly NntpService $nntpService) {}

    public function index(Request $request): \Illuminate\View\View
    {
        $spots = Spot::query()
            ->select(['id', 'title', 'poster', 'file_size', 'spot_posted_at', 'category_code', 'subcategories'])
            ->with('category:code,name')
            ->when($request->filled('cat'), fn ($q) => $q->inCategory($request->cat))
            ->when($request->filled('subcat'), fn ($q) => $q->withSubcategory((array) $request->subcat))
            ->when($request->filled('q'), fn ($q) => $q->search($request->q, SearchField::fromRequest($request->search_in)))
            ->latestFirst()
            ->paginate(max(10, min(100, $request->integer('per_page', 50))))
            ->withQueryString();

        return view('spots.index', [
            'spots' => $spots,
            'categoryTree' => Category::tree(),
            'categoriesByCode' => Category::allCached()->keyBy('code'),
            'currentCategory' => $request->cat,
            'search' => $request->q,
            'subcats' => (array) $request->subcat,
        ]);
    }

    public function show(Spot $spot): \Illuminate\View\View
    {
        $categoriesByCode = Category::allCached()->keyBy('code');

        $subcategoryNames = collect($spot->subcategories ?? [])
            ->mapWithKeys(function (string $code) use ($categoriesByCode): array {
                $category = $categoriesByCode->get($code);

                if ($category instanceof Category) {
                    if ($category->name === '-') {
                        return [];
                    }

                    return [$code => $category->name];
                }

                return [$code => $code];
            });

        $badgeCategory = $spot->resolveBadgeCategory($categoriesByCode);
        $badgeLabel = $badgeCategory->name ?? ($spot->category->name ?? $spot->category_code);

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
        abort_if(empty($spot->nzb_segments), 404, 'No NZB data available.');

        $cacheKey = 'nzb.'.$spot->id;
        $cachePath = config('spotengine.cache.nzb_path').DIRECTORY_SEPARATOR.md5($cacheKey).'.nzb';

        $nzb = file_exists($cachePath) ? file_get_contents($cachePath) : false;

        if ($nzb === false) {
            $config = $this->nntpService->getConfig();
            $nntp = $this->nntpService->makeClient();
            $nntp->connect();

            $generator = new NzbGenerator($nntp);
            $nzb = $generator->fetchNzb($spot->nzb_segments, $config['groups']['nzb'] ?? $config['groups']['spots']);
            $nntp->quit();

            @mkdir(dirname($cachePath), 0755, true);
            file_put_contents($cachePath, $nzb);
        }

        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $spot->title);
        $filename = substr((string) $filename, 0, 100).'.nzb';

        return response($nzb, 200, [
            'Content-Type' => 'application/x-nzb',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-DNZB-Name' => $spot->title,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function getImage(Spot $spot): Response
    {
        if (! $spot->image_segment) {
            return $this->placeholderImageResponse('No Image');
        }

        $cachePath = config('spotengine.cache.image_path').DIRECTORY_SEPARATOR.md5((string) $spot->image_segment).'.img';

        if (file_exists($cachePath)) {
            $imageData = file_get_contents($cachePath);

            if ($imageData !== false) {
                return $this->imageResponse($imageData);
            }
        }

        try {
            $config = $this->nntpService->getConfig();
            $nntp = $this->nntpService->makeClient();
            $nntp->connect();
            $nntp->group($config['groups']['spots']);

            $body = $nntp->body($spot->image_segment);
            $nntp->quit();

            if ($body === '' || $body === '0') {
                return $this->placeholderImageResponse('Load Failed');
            }

            $imageData = $this->decodeSpotImage($body);

            if ($imageData === '' || $imageData === '0') {
                return $this->placeholderImageResponse('Decode Failed');
            }

            @mkdir(dirname($cachePath), 0755, true);
            file_put_contents($cachePath, $imageData);

            return $this->imageResponse($imageData);
        } catch (\Throwable) {
            return $this->placeholderImageResponse('Error');
        }
    }

    public function categoriesJson(): \Illuminate\Http\JsonResponse
    {
        return response()->json(Category::tree());
    }

    private function decodeSpotImage(string $data): string
    {
        $data = str_replace(['=C', '=B', '=A', '=D'], ["\n", "\r", "\0", '='], $data);
        $data = rtrim($data, "\r\n");

        $decompressed = @gzinflate($data);
        if ($decompressed !== false && $decompressed !== '') {
            return $decompressed;
        }

        return $data;
    }

    private function imageResponse(string $data): Response
    {
        $contentType = match (true) {
            str_starts_with($data, 'GIF') => 'image/gif',
            str_starts_with($data, "\x89PNG") => 'image/png',
            default => 'image/jpeg',
        };

        return response($data, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=604800',
        ]);
    }

    private function placeholderImageResponse(string $text): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="280" viewBox="0 0 200 280"><rect fill="#1f2937" width="200" height="280"/><text x="100" y="140" text-anchor="middle" fill="#6b7280" font-family="sans-serif" font-size="14">'.htmlspecialchars($text).'</text></svg>';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
