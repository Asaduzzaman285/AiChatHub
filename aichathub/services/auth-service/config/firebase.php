<?php

declare(strict_types=1);

return [

    'default' => env('FIREBASE_PROJECT', 'app'),

    'projects' => [
        'app' => [
            /*
             * Path to the service account JSON file.
             * In Docker, /var/www is the app root (mounted volume).
             */
            'credentials' => env('FIREBASE_CREDENTIALS', '/var/www/firebase-service-account.json'),

            'database' => [
                'url' => env('FIREBASE_DATABASE_URL', ''),
            ],

            'project_id' => env('FIREBASE_PROJECT_ID', 'aichathub-ca2c2'),

            // Disable Firestore, Storage, etc. — we only use Auth
            'auth' => [
                'tenant_id' => env('FIREBASE_AUTH_TENANT_ID', null),
            ],

            // Do NOT cache to database — use array cache only in this service
            'cache_store' => 'array',

            // Disable HTTP logging to avoid overhead
            'debug_http_requests' => false,
        ],
    ],

];
