<?php

return [
    'stripe' => [
        'secret'          => env('STRIPE_SECRET_KEY'),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
    ],

    'bkash' => [
        'usd_to_bdt_rate' => (float) env('BKASH_USD_TO_BDT_RATE', 122),
    ],

    'wallet_url'       => env('WALLET_SERVICE_URL', 'http://wallet-nginx'),
    'billing_url'      => env('BILLING_SERVICE_URL', 'http://billing-nginx'),
    'notification_url' => env('NOTIFICATION_SERVICE_URL', 'http://notification-nginx'),
    'auth_url'         => env('AUTH_SERVICE_URL', 'http://auth-nginx'),
    'subscription_url' => env('SUBSCRIPTION_SERVICE_URL', 'http://subscription-nginx'),
    'internal_key'     => env('INTERNAL_SERVICE_KEY', ''),
    'frontend_url'     => env('FRONTEND_URL', 'http://localhost:3000'),
];
