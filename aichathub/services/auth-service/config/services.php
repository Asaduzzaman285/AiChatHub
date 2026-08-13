<?php

return [

    // ── Google OAuth ─────────────────────────────────────────────────────
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    // ── Internal service URLs ─────────────────────────────────────────────
    'wallet_url'   => env('WALLET_SERVICE_URL', 'http://wallet-nginx'),
    'internal_key' => env('INTERNAL_SERVICE_KEY', ''),

    // ── Internal Service URLs ────────────────────────────────────────────
    // Used by Auth Service to call other services internally
    'internal_key'         => env('INTERNAL_SERVICE_KEY', 'change-in-production'),
    'notification_url'     => env('NOTIFICATION_SERVICE_URL', 'http://notification-nginx'),
    'subscription_url'     => env('SUBSCRIPTION_SERVICE_URL', 'http://subscription-nginx'),
    'frontend_url'         => env('FRONTEND_URL', 'http://localhost:3000'),

    // Public-facing base URL for links a user actually clicks (email verification,
    // email-change confirmation). Deliberately separate from APP_URL: in production
    // APP_URL is the internal-only Docker hostname (auth-service isn't publicly
    // reachable, only api-gateway's proxy is), so a link built from APP_URL would
    // point somewhere the user's browser can never resolve. Defaults to the same
    // value APP_URL already used to be in dev, where it happens to be reachable.
    'api_public_url'       => env('API_PUBLIC_URL', 'http://localhost:8001'),

];
