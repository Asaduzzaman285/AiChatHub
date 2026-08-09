<?php

return [
    'notification_url' => env('NOTIFICATION_SERVICE_URL', 'http://notification-nginx'),
    'auth_url'         => env('AUTH_SERVICE_URL', 'http://auth-nginx'),
    'payment_url'      => env('PAYMENT_SERVICE_URL', 'http://payment-nginx'),
    'internal_key'     => env('INTERNAL_SERVICE_KEY', ''),
];
