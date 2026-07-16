<?php

use Illuminate\Support\Str;

return [
    'default' => env('CACHE_DRIVER', 'redis'),
    'stores'  => [
        'redis' => [
            'driver'     => 'redis',
            'connection' => 'cache',
        ],
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],
    ],
    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'wallet-svc'), '_').'_cache_'),
];
