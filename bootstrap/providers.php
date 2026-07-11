<?php

declare(strict_types=1);
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\NntpServiceProvider;
use App\Providers\SearchServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NntpServiceProvider::class,
    SearchServiceProvider::class,
];
