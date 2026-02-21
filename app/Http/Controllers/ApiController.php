<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SearchField;
use App\Models\Spot;
use App\Models\User;
use App\Services\NzbDownloadService;
use App\Services\Search\Contracts\SearchDriver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Newznab-compatible API for Sonarr, Radarr, and similar automation tools.
 */
class ApiController extends Controller
{
    public function __construct(private readonly NzbDownloadService $nzbService) {}

    public function handle(Request $request): Response
    {
        $type = $request->input('t');

        $noAuthResponse = match ($type) {
            'caps', 'c' => $this->caps(),
            'register', 'r' => $this->apiError(501, 'Registration via API is not available'),
            default => null,
        };
        if ($noAuthResponse !== null) {
            return $noAuthResponse;
        }

        $authRequired = ['search', 's', 'tvsearch', 'movie', 'moviesearch', 'details', 'd', 'get', 'g', 'getnzb'];
        if (! in_array($type, $authRequired, true)) {
            return $this->apiError(202, 'No such function');
        }

        $user = $this->requireUser();

        return match ($type) {
            'search', 's' => $this->search($request, $user),
            'tvsearch' => $this->tvSearch($request, $user),
            'movie', 'moviesearch' => $this->movieSearch($request, $user),
            'details', 'd' => $this->details($request, $user),
            'get', 'g', 'getnzb' => $this->getNzb($request),
        };
    }

    private function caps(): Response
    {
        $baseUrl = config('app.url');
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <caps>
          <server version="0.1" title="SpotEngine" strapline="Spotnet Web Client" url="{$baseUrl}" type="newznab"/>
          <limits max="250" default="100"/>
          <registration available="no" open="no"/>
          <searching>
            <search available="yes" supportedParams="q"/>
            <tv-search available="yes" supportedParams="q,rid,tvmazeid,season,ep"/>
            <movie-search available="yes" supportedParams="q,imdbid"/>
            <pc-search available="yes" supportedParams="q"/>
            <audio-search available="yes" supportedParams="q"/>
          </searching>
          <categories>
            <category id="1000" name="Console">
              <subcat id="1010" name="NDS"/>
              <subcat id="1020" name="PSP"/>
              <subcat id="1030" name="Wii"/>
              <subcat id="1040" name="Xbox"/>
              <subcat id="1050" name="Xbox 360"/>
              <subcat id="1080" name="PS3"/>
            </category>
            <category id="2000" name="Movies">
              <subcat id="2030" name="SD"/>
              <subcat id="2040" name="HD"/>
              <subcat id="2050" name="BluRay"/>
              <subcat id="2060" name="3D"/>
            </category>
            <category id="3000" name="Audio">
              <subcat id="3010" name="MP3"/>
              <subcat id="3020" name="Video"/>
              <subcat id="3040" name="Lossless"/>
            </category>
            <category id="4000" name="PC">
              <subcat id="4020" name="Windows"/>
              <subcat id="4030" name="Mac"/>
              <subcat id="4040" name="Mobile"/>
              <subcat id="4050" name="Games"/>
            </category>
            <category id="5000" name="TV">
              <subcat id="5020" name="Foreign"/>
              <subcat id="5030" name="SD"/>
              <subcat id="5040" name="HD"/>
              <subcat id="5050" name="Other"/>
              <subcat id="5060" name="Sport"/>
              <subcat id="5070" name="Anime"/>
            </category>
            <category id="6000" name="XXX">
              <subcat id="6010" name="DVD"/>
              <subcat id="6020" name="WMV"/>
              <subcat id="6030" name="XviD"/>
              <subcat id="6040" name="x264"/>
            </category>
            <category id="7000" name="Other">
              <subcat id="7010" name="Misc"/>
              <subcat id="7020" name="Ebook"/>
            </category>
          </categories>
        </caps>
        XML;

        return response(trim($xml), 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    private function search(Request $request, User $user): Response
    {
        $query = $request->input('q', '');
        $limit = min(100, max(1, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $page = (int) floor($offset / $limit) + 1;

        $spots = Spot::query()
            ->with('category')
            ->when($request->filled('cat'), fn ($q) => $q->inCategory($this->mapNewznabCategory($request->cat)))
            ->when($query, fn ($q) => $q->search($query, SearchField::Title))
            ->latestFirst()
            ->paginate($limit, ['*'], 'page', $page);

        return $this->rssResponse($spots->items(), $user, $spots->total(), $offset);
    }

    private function tvSearch(Request $request, User $user): Response
    {
        $query = trim((string) $request->input('q', ''));
        $season = $request->input('season', '');
        $episode = $request->input('ep', '');
        $limit = min(100, max(1, (int) $request->input('limit', 50)));

        $searchVariants = $this->buildTvSearchVariants($query, $season, $episode);
        $searchDriver = app(SearchDriver::class);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Spot> $spots */
        $spots = Spot::query()
            ->with('category')
            ->inCategory('01')
            ->when(
                $searchVariants !== [],
                fn ($q) => $searchDriver->searchVariants($q, $searchVariants, SearchField::Title)
            )
            ->orderBy('spot_posted_at', 'desc')
            ->limit($limit)
            ->get();

        return $this->rssResponse($spots->all(), $user);
    }

    private function movieSearch(Request $request, User $user): Response
    {
        $query = $request->input('q', '');
        $limit = min(100, max(1, (int) $request->input('limit', 50)));

        $spots = Spot::query()
            ->with('category')
            ->inCategory('01')
            ->when($query, fn ($q) => $q->search($query, SearchField::Title))
            ->latestFirst()
            ->limit($limit)
            ->get();

        return $this->rssResponse($spots->all(), $user);
    }

    private function details(Request $request, User $user): Response
    {
        $spot = Spot::with('category')->findOrFail((int) $request->input('id'));

        return $this->rssResponse([$spot], $user);
    }

    private function getNzb(Request $request): Response
    {
        $spot = Spot::findOrFail((int) $request->input('id'));
        $nzb = $this->nzbService->fetchNzb($spot);

        return response($nzb, 200, [
            'Content-Type' => 'application/x-nzb',
            'Content-Disposition' => 'attachment; filename="'.$this->nzbService->filename($spot).'"',
            'X-DNZB-Name' => $spot->title,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Build TV search query variants mirroring Spotweb behaviour.
     *
     * Given "Breaking Bad", season=1, episode=2 produces:
     *   ["Breaking Bad S01E02", "Breaking Bad Season 1 Episode 2"]
     *
     * @return string[]
     */
    private function buildTvSearchVariants(string $query, string $season, string $episode): array
    {
        if ($query === '' || $query === '0') {
            return [];
        }

        if ($season && $episode) {
            $s = str_pad($season, 2, '0', STR_PAD_LEFT);
            $e = str_pad($episode, 2, '0', STR_PAD_LEFT);

            return [
                "{$query} S{$s}E{$e}",
                "{$query} Season {$season} Episode {$episode}",
            ];
        }

        if ($season !== '' && $season !== '0') {
            $s = str_pad($season, 2, '0', STR_PAD_LEFT);

            return [
                "{$query} S{$s}",
                "{$query} Season {$season}",
            ];
        }

        return [$query];
    }

    /**
     * @param  Spot[]  $spots
     * @param  int|null  $total  Total matching results (for pagination). When null, uses count of $spots.
     * @param  int|null  $offset  Offset of current page. When null, uses 0.
     */
    private function rssResponse(array $spots, ?User $user, ?int $total = null, ?int $offset = null): Response
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $apiKey = $user !== null ? ($user->api_token ?? '') : '';

        $items = '';
        foreach ($spots as $spot) {
            $pubDate = $spot->spot_posted_at->format('r');
            $nzCategory = $this->mapSpotCategory($spot->category_code);
            $categoryName = e($spot->category?->name ?? 'Unknown'); // @phpstan-ignore nullsafe.neverNull
            $downloadUrl = e("{$baseUrl}/api?t=get&id={$spot->id}&apikey={$apiKey}");

            $items .= <<<XML

              <item>
                <title>{$this->xmlEncode($spot->title)}</title>
                <guid isPermaLink="true">{$baseUrl}/spots/{$spot->id}</guid>
                <link>{$downloadUrl}</link>
                <pubDate>{$pubDate}</pubDate>
                <category>{$categoryName}</category>
                <description>{$this->xmlEncode($spot->description ?? $spot->title)}</description>
                <enclosure url="{$downloadUrl}" length="{$spot->file_size}" type="application/x-nzb"/>
                <newznab:attr name="category" value="{$nzCategory}"/>
                <newznab:attr name="size" value="{$spot->file_size}"/>
                <newznab:attr name="poster" value="{$this->xmlEncode($spot->poster ?? 'Unknown')}"/>
              </item>
            XML;
        }

        $total = $total ?? \count($spots);
        $offset = $offset ?? 0;
        $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:newznab="http://www.newznab.com/DTD/2010/feeds/attributes/">
        <channel>
          <title>SpotEngine</title>
          <description>SpotEngine Spotnet Client</description>
          <link>{$baseUrl}</link>
          <newznab:response offset="{$offset}" total="{$total}"/>{$items}
        </channel>
        </rss>
        XML;

        return response(trim($xml), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    private function apiError(int $code, string $message): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<error code="'.$code.'" description="'.e($message).'"/>';

        return response($xml, 400, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    private function requireUser(): User
    {
        $user = auth('api')->user();

        if (! $user instanceof User) {
            $description = filled(request()->input('apikey')) ? 'Incorrect API key' : 'API key is required';
            abort(response(
                '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<error code="100" description="'.$description.'"/>',
                401,
                ['Content-Type' => 'text/xml; charset=utf-8']
            ));
        }

        return $user;
    }

    private function mapNewznabCategory(string $category): ?string
    {
        $cat = (int) explode(',', $category)[0];

        return match (true) {
            $cat >= 1000 && $cat < 2000 => '03', // Console → Games
            $cat >= 2000 && $cat < 3000 => '01', // Movies → Image
            $cat >= 3000 && $cat < 4000 => '02', // Audio → Audio
            $cat === 4050 => '03', // PC/Games → Games
            $cat >= 4000 && $cat < 5000 => '04', // PC/Apps → Applications
            $cat >= 5000 && $cat < 6000 => '01', // TV → Image (series subtype)
            $cat >= 6000 && $cat < 7000 => '01', // XXX → Image (erotica subtype)
            $cat >= 7000 && $cat < 8000 => '01', // Other/Ebook → Image
            default => null,
        };
    }

    private function mapSpotCategory(string $code): int
    {
        return match ($code) {
            '01' => 2000,
            '02' => 3000,
            '03' => 1000,
            '04' => 4000,
            default => 7000,
        };
    }

    private function xmlEncode(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
