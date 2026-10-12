<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | NNTP connection
    |--------------------------------------------------------------------------
    | Usenet server and newsgroup used for fetching spots. SSL is typical on
    | port 563; connections controls parallelism for XOVER/XHDR retrieval.
    |
    | groups.spots: metadata (X-XML headers), e.g. free.pt
    | groups.nzb: NZB segment article bodies, e.g. alt.binaries.ftd
    */
    'nntp' => [
        'driver' => env('NNTP_DRIVER', 'parallel'),
        'host' => env('NNTP_HOST', ''),
        'port' => (int) env('NNTP_PORT', 563),
        'ssl' => (bool) env('NNTP_SSL', true),
        'username' => env('NNTP_USERNAME', ''),
        'password' => env('NNTP_PASSWORD', ''),
        'timeout' => (int) env('NNTP_TIMEOUT', 60),
        'connections' => (int) env('NNTP_CONNECTIONS', 20),
        'verify_peer' => (bool) env('NNTP_TLS_VERIFY', true),
        'groups' => [
            'spots' => env('NNTP_GROUP_SPOTS', 'free.pt'),
            'nzb' => env('NNTP_GROUP_NZB', 'alt.binaries.ftd'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | NNTP payload limits
    |--------------------------------------------------------------------------
    | Bound untrusted article aggregation and decoding before allocating output.
    | Byte limits include encoded transport data unless named decoded or output.
    */
    'payload_limits' => [
        'nntp_line_bytes' => (int) env('NNTP_MAX_LINE_BYTES', 1_048_576),
        'nntp_header_bytes' => (int) env('NNTP_MAX_HEADER_BYTES', 2_097_152),
        'nntp_body_bytes' => (int) env('NNTP_MAX_BODY_BYTES', 16_777_216),
        'nntp_article_bytes' => (int) env('NNTP_MAX_ARTICLE_BYTES', 18_874_368),
        'nntp_response_bytes' => (int) env('NNTP_MAX_RESPONSE_BYTES', 67_108_864),
        'nntp_response_lines' => (int) env('NNTP_MAX_RESPONSE_LINES', 100_000),
        'nzb_segments' => (int) env('NZB_MAX_SEGMENTS', 128),
        'nzb_input_bytes' => (int) env('NZB_MAX_INPUT_BYTES', 33_554_432),
        'nzb_compressed_bytes' => (int) env('NZB_MAX_COMPRESSED_BYTES', 16_777_216),
        'nzb_output_bytes' => (int) env('NZB_MAX_OUTPUT_BYTES', 67_108_864),
        'image_segments' => (int) env('IMAGE_MAX_SEGMENTS', 64),
        'image_input_bytes' => (int) env('IMAGE_MAX_INPUT_BYTES', 50_000_000),
        'image_decoded_bytes' => (int) env('IMAGE_MAX_DECODED_BYTES', 25_000_000),
        'image_pixels' => (int) env('IMAGE_MAX_PIXELS', 40_000_000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spotnet moderation
    |--------------------------------------------------------------------------
    | Trusted protocol keys used to authenticate globally-authorized moderation
    | commands. Key 2 is the published Team ONG moderator key.
    */
    'moderation' => [
        'public_keys' => [
            2 => [
                'modulus' => 'ys8WSlqonQMWT8ubG0tAA2Q07P36E+CJmb875wSR1XH7IFhEi0CCwlUzNqBFhC+P',
                'exponent' => 'AQAB',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache (NZB and images)
    |--------------------------------------------------------------------------
    | Local paths and retention: files older than the retention days are
    | removed by the spot cache prune command. Set retention to 0 to
    | disable pruning entirely (useful when pre-caching with spot:precache).
    */
    'cache' => [
        'nzb_retention_days' => (int) env('CACHE_NZB_RETENTION_DAYS', 30),
        'image_retention_days' => (int) env('CACHE_IMAGE_RETENTION_DAYS', 30),
        'nzb_path' => storage_path('app/cache/nzb'),
        'image_path' => storage_path('app/cache/images'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spot retrieval
    |--------------------------------------------------------------------------
    | batch_size: article-number slots per XOVER batch. XOVER is fast and
    |   handles large ranges well. Not every slot holds an article (gaps,
    |   cancelled posts), so actual HEAD requests are typically 60-80% of
    |   this number.
    */
    'retrieval' => [
        'batch_size' => (int) env('RETRIEVAL_BATCH_SIZE', 5000),
        'memory_limit' => env('RETRIEVAL_MEMORY_LIMIT', '1G'),
        /*
         * Unix timestamp cutoff for spot retrieval. Spots posted on or before this
         * moment are skipped during indexing — pre-NZB Spotnet spots from before
         * 2010-11-24 never had alt.binaries.ftd attachments. Set to 0 to disable.
         */
        'retrieve_newer_than' => (int) env('RETRIEVE_NEWER_THAN', 1290578400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing cache
    |--------------------------------------------------------------------------
    | When enabled, paginated spot listings are cached to avoid redundant
    | queries between retrieval runs. Cache is flushed automatically when
    | new spots are inserted.
    */
    'listing_cache' => [
        'enabled' => (bool) env('LISTING_CACHE_ENABLED', false),
        'ttl' => (int) env('LISTING_CACHE_TTL', 30), // minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | User download history
    |--------------------------------------------------------------------------
    | Records in user_downloads older than this many days are removed by the
    | model:prune command. Set retention to 0 to disable pruning.
    */
    'downloads' => [
        'retention_days' => (int) env('DOWNLOAD_RETENTION_DAYS', 90),
    ],

    'newznab' => [
        'rate_limit_per_minute' => (int) env('NEWZNAB_RATE_LIMIT_PER_MINUTE', 60),
    ],

    /** Whether new user registration is allowed. */
    'registration_open' => (bool) env('REGISTRATION_OPEN', false),

    /*
    |--------------------------------------------------------------------------
    | Spotweb external black/whitelist
    |--------------------------------------------------------------------------
    | RSA modulus lists from Spotweb/Spotnet clients. Each <Key> body is hashed
    | into a spotter ID (poster_key_id). Blacklisted spotters are rejected;
    | whitelisted spotters bypass blacklist matches.
    */
    'lists' => [
        'blacklist_url' => env('SPOTENGINE_BLACKLIST_URL', 'https://spotlist.store/spotnet/blacklist.xml'),
        'whitelist_url' => env('SPOTENGINE_WHITELIST_URL', 'https://spotlist.store/spotnet/whitelist.xml'),
    ],
];
