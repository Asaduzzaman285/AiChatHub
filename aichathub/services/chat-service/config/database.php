<?php
return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', 'postgres'),
            'port'     => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ai_chathub_db'),
            'username' => env('DB_USERNAME', 'chat_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8',
            'schema'   => env('DB_SCHEMA', 'chat_svc'),
            'sslmode'  => 'prefer',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client'  => 'phpredis',
        'default' => ['host' => env('REDIS_HOST', 'redis'), 'password' => env('REDIS_PASSWORD', null), 'port' => env('REDIS_PORT', '6379'), 'database' => '0'],
        // config/cache.php's redis store points at this connection name — this
        // service had no config/cache.php at all until the chat:expire-private-
        // sessions scheduler surfaced it: schedule:list's mutex/lock check fell back
        // to a database-backed cache lock whose table (cache_locks) was never
        // migrated, throwing "Undefined table: cache_locks" even though CACHE_DRIVER
        // was already set to redis in .env — same root cause and same fix wallet-
        // service needed for an unrelated reason (see its own comment here).
        'cache' => ['host' => env('REDIS_HOST', 'redis'), 'password' => env('REDIS_PASSWORD', null), 'port' => env('REDIS_PORT', '6379'), 'database' => '1'],
    ],
];
