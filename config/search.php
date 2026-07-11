<?php

declare(strict_types=1);

return [
    'driver' => env('SEARCH_DRIVER', 'database'),

    'drivers' => [
        'database' => [],

        'manticore' => [
            'scheme' => env('MANTICORE_SCHEME', 'http'),
            'host' => env('MANTICORE_HOST', '127.0.0.1'),
            'port' => (int) env('MANTICORE_PORT', 9308),
            'index' => env('MANTICORE_INDEX', 'spots'),
            'timeout' => (int) env('MANTICORE_TIMEOUT', 5),
            'max_matches' => (int) env('MANTICORE_MAX_MATCHES', 100000),
            'sync_batch_size' => (int) env('MANTICORE_SYNC_BATCH_SIZE', 500),
        ],
    ],
];
