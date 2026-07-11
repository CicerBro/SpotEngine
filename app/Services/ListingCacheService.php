<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ListingCacheService
{
    private const string TAG = 'spots-listing';

    private const string KEY_PREFIX = 'spots:listing:';

    private const array RELEVANT_PARAMS = ['cat', 'subcat', 'q', 'search_in', 'per_page', 'cursor'];

    public function remember(Request $request, Closure $callback): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $key = $this->cacheKey($request);
        $ttl = now()->addMinutes($this->ttl());

        if ($this->supportsTags()) {
            return Cache::tags([self::TAG])->remember($key, $ttl, $callback);
        }

        return Cache::remember($key, $ttl, $callback);
    }

    public function flush(): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        if ($this->supportsTags()) {
            Cache::tags([self::TAG])->flush();

            return;
        }

        $prefix = config('cache.prefix', '');
        DB::table('cache')
            ->where('key', 'like', $prefix.self::KEY_PREFIX.'%')
            ->delete();
    }

    private function cacheKey(Request $request): string
    {
        $params = $request->only(self::RELEVANT_PARAMS);
        $params['_response'] = $request->expectsJson() ? 'json' : 'html';
        ksort($params);

        return self::KEY_PREFIX.sha1(serialize($params));
    }

    private function isEnabled(): bool
    {
        return (bool) config('spotengine.listing_cache.enabled', false);
    }

    private function ttl(): int
    {
        return (int) config('spotengine.listing_cache.ttl', 5);
    }

    private function supportsTags(): bool
    {
        return Cache::supportsTags();
    }
}
