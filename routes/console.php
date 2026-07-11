<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('spot:retrieve')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('spot:search-sync')
    ->everyMinute()
    ->when(fn (): bool => config('search.driver') === 'manticore')
    ->withoutOverlapping();
Schedule::command('spot:prune-cache')->daily()->at('03:00');
