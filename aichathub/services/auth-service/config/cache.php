<?php

use Illuminate\Support\Str;

return [

    /*
     * Default cache store — explicitly redis.
     * We do NOT use the 'database' driver in this service.
     * The 'cache' table does not exist and should never be created here.
     */
    'default' => env('CACHE_DRIVER', 'redis'),

    'stores' => [

        'redis' => [
            'driver'     => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver' => 'file',
            'path'   => storage_path('framework/cache/data'),
        ],

        // Intentionally no 'database' store defined.
        // This prevents any accidental cache.php misconfiguration
        // from trying to use a non-existent cache table.

    ],

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'auth-svc'), '_').'_cache_'),

];
