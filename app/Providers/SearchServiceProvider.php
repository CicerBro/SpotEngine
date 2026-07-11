<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Search\Contracts\SearchDriver;
use App\Services\Search\Drivers\DatabaseSearchDriver;
use App\Services\Search\Drivers\ManticoreSearchDriver;
use App\Services\Search\ManticoreDocumentMapper;
use Illuminate\Support\ServiceProvider;

class SearchServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(SearchDriver::class, fn (): SearchDriver => match (config('search.driver')) {
            'manticore' => new ManticoreSearchDriver(
                host: (string) config('search.drivers.manticore.host'),
                port: (int) config('search.drivers.manticore.port'),
                index: (string) config('search.drivers.manticore.index'),
                documentMapper: new ManticoreDocumentMapper,
                scheme: (string) config('search.drivers.manticore.scheme'),
                timeout: (int) config('search.drivers.manticore.timeout'),
                maxMatches: (int) config('search.drivers.manticore.max_matches'),
            ),
            'database' => new DatabaseSearchDriver,
            default => throw new \InvalidArgumentException(
                'SEARCH_DRIVER must be either database or manticore.',
            ),
        });
    }
}
