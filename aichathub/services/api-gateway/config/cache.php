<?php

return [

    // No config/cache.php existed before — Laravel's zero-config default falls back to
    // the "database" store, which api-gateway has no database connection for at all
    // (it's a pure proxy, no Eloquent/migrations). CACHE_DRIVER=redis was already set in
    // .env but had no effect without this file — needed for the rate limiter (RateLimiter
    // facade uses the default cache store) to actually share state across php-fpm workers.
    'default' => env('CACHE_DRIVER', env('CACHE_STORE', 'redis')),

    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
        'array' => [
            'driver' => 'array',
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'aichathub_gateway_cache_'),

];
