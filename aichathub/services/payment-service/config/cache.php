<?php

return [

    // No config/cache.php existed before — Laravel's zero-config default falls back to
    // the "database" store, which queries a "cache" table that was never migrated for
    // this service. That was latent until the bKash package's getToken() became this
    // service's first Cache:: caller (StripeGateway never used the Cache facade),
    // surfacing as "Undefined table: cache" on the very first bKash checkout attempt.
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

    'prefix' => env('CACHE_PREFIX', 'aichathub_payment_cache_'),

];
