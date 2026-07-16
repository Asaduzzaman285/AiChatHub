<?php

return [

    // Downstream service URLs — must match Docker Compose service names
    'auth_url'         => env('AUTH_SERVICE_URL',         'http://auth-nginx'),
    'subscription_url' => env('SUBSCRIPTION_SERVICE_URL', 'http://subscription-nginx'),
    'wallet_url'       => env('WALLET_SERVICE_URL',       'http://wallet-nginx'),
    'payment_url'      => env('PAYMENT_SERVICE_URL',      'http://payment-nginx'),
    'ai_gateway_url'   => env('AI_GATEWAY_SERVICE_URL',   'http://ai-gateway-nginx'),
    'chat_url'         => env('CHAT_SERVICE_URL',         'http://chat-nginx'),
    'billing_url'      => env('BILLING_SERVICE_URL',      'http://billing-nginx'),

    // Internal service auth key
    'internal_key'     => env('INTERNAL_SERVICE_KEY', ''),

];
