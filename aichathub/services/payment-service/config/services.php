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
        // Last-resort fallback only, used when the live currency_rates lookup
        // against subscription-service (BkashGateway::usdToBdt()) can't be
        // reached — that internal endpoint (admin-configurable exchange rate +
        // margin/tax/VAT/withholding) is the real source of truth now.
        'usd_to_bdt_rate' => (float) env('BKASH_USD_TO_BDT_RATE', 122),

        // Sandbox path for staging.alveta.ai — same backend, same deployment as
        // production (see StripeGateway::useSandboxIfOrigin(), mirrored here by
        // BkashGateway::useSandboxIfOrigin()). Only ever selected when a
        // request's Origin exactly matches this; every other case (including no
        // Origin header, e.g. server-to-server calls) keeps using the live
        // credentials in 'bkash.credentials' (config/bkash.php), unchanged.
        'sandbox_origin'     => env('BKASH_SANDBOX_ORIGIN'),
        'sandbox_app_key'    => env('BKASH_SANDBOX_APP_KEY', ''),
        'sandbox_app_secret' => env('BKASH_SANDBOX_APP_SECRET', ''),
        'sandbox_username'   => env('BKASH_SANDBOX_USERNAME', ''),
        'sandbox_password'   => env('BKASH_SANDBOX_PASSWORD', ''),
    ],

    'wallet_url'       => env('WALLET_SERVICE_URL', 'http://wallet-nginx'),
    'billing_url'      => env('BILLING_SERVICE_URL', 'http://billing-nginx'),
    'notification_url' => env('NOTIFICATION_SERVICE_URL', 'http://notification-nginx'),
    'auth_url'         => env('AUTH_SERVICE_URL', 'http://auth-nginx'),
    'subscription_url' => env('SUBSCRIPTION_SERVICE_URL', 'http://subscription-nginx'),
    'internal_key'     => env('INTERNAL_SERVICE_KEY', ''),
    'frontend_url'     => env('FRONTEND_URL', 'http://localhost:3000'),
];
