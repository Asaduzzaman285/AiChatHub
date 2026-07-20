<?php

return [
    'wallet_url'       => env('WALLET_SERVICE_URL', 'http://wallet-nginx'),
    'billing_url'      => env('BILLING_SERVICE_URL', 'http://billing-nginx'),
    'notification_url' => env('NOTIFICATION_SERVICE_URL', 'http://notification-nginx'),
    'auth_url'         => env('AUTH_SERVICE_URL', 'http://auth-nginx'),
    'internal_key'     => env('INTERNAL_SERVICE_KEY', ''),
];
