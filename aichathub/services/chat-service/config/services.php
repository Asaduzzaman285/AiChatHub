<?php

return [
    'internal_key'   => env('INTERNAL_SERVICE_KEY', ''),
    'ai_gateway_url' => env('AI_GATEWAY_SERVICE_URL', 'http://ai-gateway-nginx'),

    // Defaults true (production posture) — local dev sets CLAMAV_ENABLED=false since
    // clamd's resident virus DB (~800MB) doesn't fit alongside the rest of this stack
    // on a memory-constrained dev machine. See FileAttachmentController::upload().
    'clamav_enabled' => env('CLAMAV_ENABLED', true),
    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => env('CLAMAV_PORT', 3310),
    ],
];
