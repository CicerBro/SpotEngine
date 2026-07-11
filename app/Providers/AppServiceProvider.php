<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Category;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        Date::use(CarbonImmutable::class);
        DB::prohibitDestructiveCommands(app()->isProduction());

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
