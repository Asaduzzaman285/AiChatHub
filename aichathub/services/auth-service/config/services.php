<?php

return [

    // ── Google OAuth ─────────────────────────────────────────────────────
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    // ── Internal Service URLs ────────────────────────────────────────────
    // Used by Auth Service to call other services internally
    'internal_key'         => env('INTERNAL_SERVICE_KEY', 'change-in-production'),
    'notification_url'     => env('NOTIFICATION_SERVICE_URL', 'http://notification-nginx'),
    'subscription_url'     => env('SUBSCRIPTION_SERVICE_URL', 'http://subscription-nginx'),

];
