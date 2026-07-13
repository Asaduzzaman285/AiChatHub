<?php

return [

    'default' => env('AI_DEFAULT_PROVIDER', 'openai'),

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key'    => env('OPENAI_API_KEY'),
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'key'    => env('ANTHROPIC_API_KEY'),
        ],
        'gemini' => [
            'driver' => 'gemini',
            'key'    => env('GEMINI_API_KEY'),
        ],
        'xai' => [
            'driver' => 'xai',
            'key'    => env('XAI_API_KEY'),
        ],
        'elevenlabs' => [
            'driver' => 'elevenlabs',
            'key'    => env('ELEVENLABS_API_KEY'),
        ],
    ],

    'caching' => [
        'embeddings' => [
            'cache' => env('AI_CACHE_EMBEDDINGS', false),
            'store' => env('CACHE_STORE', 'redis'),
        ],
    ],

    'timeouts' => [
        'request' => env('AI_REQUEST_TIMEOUT_SECONDS', 60),
        'stream'  => env('AI_STREAM_TIMEOUT_SECONDS', 120),
    ],

];
