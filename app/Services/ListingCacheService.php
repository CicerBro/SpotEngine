<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ListingCacheService
{
    private const string GENERATION_KEY = 'spots:listing:generation';

    private const string KEY_PREFIX = 'spots:listing:v2:';

    private const array RELEVANT_PARAMS = ['cat', 'subcat', 'q', 'search_in', 'per_page', 'cursor', 'new'];

    public function remember(Request $request, Closure $callback): mixed
    {
        if (! $this->isEnabled()) {
            return $callback();
        }

        $key = $this->cacheKey($request);
        $ttl = now()->addMinutes($this->ttl());

        return Cache::remember($key, $ttl, $callback);
    }

    public function flush(): void
    {
        Cache::forever(self::GENERATION_KEY, (string) Str::uuid());
    }

    public function generation(): string
    {
        $generation = Cache::get(self::GENERATION_KEY);

        if (\is_string($generation)) {
            return $generation;
        }

        $generation = (string) Str::uuid();
        Cache::add(self::GENERATION_KEY, $generation, now()->addYear());

        return (string) Cache::get(self::GENERATION_KEY, $generation);
    }

    private function cacheKey(Request $request): string
    {
        $params = $request->only(self::RELEVANT_PARAMS);

        if (isset($params['subcat'])) {
            $subcategories = array_values(array_unique(array_filter((array) $params['subcat'], \is_string(...))));
            sort($subcategories, SORT_STRING);
            if ($subcategories === []) {
                unset($params['subcat']);
            } else {
                $params['subcat'] = $subcategories;
            }
        }

        $params['_response'] = $request->expectsJson() ? 'json' : 'html';
        $params['_generation'] = $this->generation();

        if ($request->boolean('new')) {
            $user = $request->user();
            $params['_user'] = $user?->id;
            $params['_read_until'] = $user?->spots_read_until?->format('U.u');
        }

        ksort($params);

        return self::KEY_PREFIX . sha1(serialize($params));
    }

    private function isEnabled(): bool
    {
        return (bool) config('spotengine.listing_cache.enabled', false);
    }

    private function ttl(): int
    {
        return (int) config('spotengine.listing_cache.ttl', 5);
    }
}
