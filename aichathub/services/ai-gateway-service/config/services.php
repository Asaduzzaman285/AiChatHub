<?php

return [
    'wallet_url'       => env('WALLET_SERVICE_URL', 'http://wallet-nginx'),
    'subscription_url' => env('SUBSCRIPTION_SERVICE_URL', 'http://subscription-nginx'),
    'chat_url'         => env('CHAT_SERVICE_URL', 'http://chat-nginx'),
    'internal_key'     => env('INTERNAL_SERVICE_KEY', ''),
];
