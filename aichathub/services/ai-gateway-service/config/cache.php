<?php

return [

    // No config/cache.php existed before — Laravel's zero-config default falls back to
    // the "database" store, which queries a "cache" table that was never migrated for
    // this service. That silently crashed `queue:work` on startup (it checks the cache
    // for a queue:restart signal before pulling its first job) with no clear error
    // visible unless you run it in the foreground.
    'default' => env('CACHE_DRIVER', env('CACHE_STORE', 'redis')),

    'stores' => [
        'redis' => [
            'driver' => 'redis',
            // Only a 'default' Redis connection is configured (config/database.php) —
            // no separate 'cache' connection exists for this service.
            'connection' => 'default',
        ],
        'array' => [
            'driver' => 'array',
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'aichathub_ai_gateway_cache_'),

];
