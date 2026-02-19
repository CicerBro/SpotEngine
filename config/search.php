<?php

declare(strict_types=1);

return [
    'driver' => env('SEARCH_DRIVER', 'database'),

    'drivers' => [
        'database' => [],

        'manticore' => [
            'host' => env('MANTICORE_HOST', '127.0.0.1'),
            'port' => (int) env('MANTICORE_PORT', 9308),
            'index' => env('MANTICORE_INDEX', 'spots'),
        ],
    ],
];
