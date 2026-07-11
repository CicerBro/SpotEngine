<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict();
        Model::unguard();
        Date::use(CarbonImmutable::class);
        DB::prohibitDestructiveCommands(app()->isProduction());

        RateLimiter::for('newznab', function (Request $request): Limit {
            $user = auth('api')->user();
            $key = $user !== null ? 'user:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();

            return Limit::perMinute(max(1, (int) config('spotengine.newznab.rate_limit_per_minute', 60)))
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
                        .'<error code="500" description="API rate limit exceeded"/>';

                    return response($xml, 429, [
                        ...$headers,
                        'Content-Type' => 'text/xml; charset=utf-8',
                    ]);
                });
        });

        View::composer('partials.sidebar', function (\Illuminate\View\View $view): void {
            if (! $view->offsetExists('categoryTree')) {
                $view->with('categoryTree', Category::tree());
            }

            if (! $view->offsetExists('categoriesByCode')) {
                $view->with('categoriesByCode', Category::allCached()->keyBy('code'));
            }
        });
    }
}
