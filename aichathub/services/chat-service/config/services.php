<?php

return [
    'internal_key'   => env('INTERNAL_SERVICE_KEY', ''),
    'ai_gateway_url' => env('AI_GATEWAY_SERVICE_URL', 'http://ai-gateway-nginx'),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => env('CLAMAV_PORT', 3310),
    ],
];
