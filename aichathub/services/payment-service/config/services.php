<?php

return [
    'stripe' => [
        'secret'          => env('STRIPE_SECRET_KEY'),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        // Sandbox path for staging.alveta.ai (same backend as production — see
        // StripeGateway::useSandboxIfOrigin()). Only ever selected when a request's
        // origin exactly matches sandbox_origin; every other case (including no
        // Origin header at all, e.g. server-to-server calls) keeps using the live
        // keys above, unchanged.
        'test_secret'          => env('STRIPE_TEST_SECRET_KEY'),
        'test_publishable_key' => env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'sandbox_origin'       => env('STRIPE_SANDBOX_ORIGIN'),
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
