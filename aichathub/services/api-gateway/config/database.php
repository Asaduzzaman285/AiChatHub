<?php

return [

    // api-gateway is a pure proxy — no Eloquent, no migrations, no real DB connection.
    // 'default'/'connections' exist only because the framework expects the key; nothing
    // ever resolves it. The 'redis' block is the actual reason this file exists — needed
    // by the cache store (config/cache.php) and rate limiter.
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [],

    'redis' => [
        'client'  => 'phpredis',
        'default' => [
            'host'     => env('REDIS_HOST', 'redis'),
            'password' => env('REDIS_PASSWORD', null),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => '0',
        ],
    ],

];
