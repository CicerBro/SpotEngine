<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Search\Drivers\DatabaseSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use Illuminate\Support\ServiceProvider;

class SearchServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(\App\Services\Search\Contracts\SearchDriver::class, fn (): \App\Services\Search\Contracts\SearchDriver => match (config('search.driver')) {
            'manticore' => new ManticoreSearchDriver(
                host: config('search.drivers.manticore.host'),
                port: config('search.drivers.manticore.port'),
                index: config('search.drivers.manticore.index'),
            ),
            default => new DatabaseSearchDriver,
        });
    }
}
