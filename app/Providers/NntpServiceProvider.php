<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Nntp\NntpService;
use Illuminate\Support\ServiceProvider;

class NntpServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(NntpService::class, fn (): NntpService => new NntpService(
            config('spotengine.nntp'),
        ));
    }
}
