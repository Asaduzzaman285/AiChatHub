<?php
return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', 'postgres'),
            'port'     => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'ai_chathub_db'),
            'username' => env('DB_USERNAME', 'payment_app'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8',
            'schema'   => env('DB_SCHEMA', 'payment_svc'),
            'sslmode'  => 'prefer',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client'  => 'phpredis',
        'default' => ['host' => env('REDIS_HOST', 'redis'), 'password' => env('REDIS_PASSWORD', null), 'port' => env('REDIS_PORT', '6379'), 'database' => '0'],
    ],
];
