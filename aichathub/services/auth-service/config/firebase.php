<?php

return [
    'credentials' => env('FIREBASE_CREDENTIALS', base_path('firebase-service-account.json')),
    'database'    => ['url' => env('FIREBASE_DATABASE_URL', '')],
    'project_id'  => env('FIREBASE_PROJECT_ID', 'aichathub-ca2c2'),
];
