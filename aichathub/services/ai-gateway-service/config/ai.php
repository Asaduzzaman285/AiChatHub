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
        'deepseek' => [
            'driver' => 'deepseek',
            'key'    => env('DEEPSEEK_API_KEY'),
        ],
        // Perplexity has no dedicated driver in laravel/ai, but its API is
        // OpenAI-compatible — the package's generic 'openai-compatible' driver
        // (Laravel\Ai\Providers\OpenAiCompatibleProvider) just needs a base 'url'
        // plus a default model, no custom driver code required.
        //
        // stream_options.include_usage on every openai-compatible provider below —
        // confirmed live (a real Kimi response reporting prompt_tokens=0,
        // completion_tokens=0, cost=0 despite generating real content) and confirmed
        // in the vendor source: OpenAiCompatibleGateway::streamOptions() reads
        // provider->additionalConfiguration()['stream_options'], which is just this
        // config array — it does NOT fall back to requesting usage by default (a
        // same-named trait method in PerformsChatCompletionSteps.php does default to
        // include_usage:true, but the concrete class's own method of the same name
        // silently shadows it, since a class method always wins over a trait's).
        // Without this key, the provider's Chat-Completions-style stream has no signal
        // to ever emit a final usage chunk, so every openai-compatible provider —
        // not just Moonshot — was silently reporting zero usage/cost.
        'perplexity' => [
            'driver' => 'openai-compatible',
            'key'    => env('PERPLEXITY_API_KEY'),
            'url'    => 'https://api.perplexity.ai',
            'stream_options' => ['include_usage' => true],
            'models' => [
                'text' => [
                    'default'   => 'sonar',
                    'cheapest'  => 'sonar',
                    'smartest'  => 'sonar-pro',
                ],
            ],
        ],
        'elevenlabs' => [
            'driver' => 'elevenlabs',
            'key'    => env('ELEVENLABS_API_KEY'),
        ],
        // Same openai-compatible pattern as Perplexity — Alibaba's DashScope
        // exposes an OpenAI-compatible path for Qwen. International endpoint,
        // not the mainland-China one (different base URL, different key format).
        'qwen' => [
            'driver' => 'openai-compatible',
            'key'    => env('QWEN_API_KEY'),
            'url'    => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1',
            'stream_options' => ['include_usage' => true],
            'models' => [
                'text' => [
                    'default'   => 'qwen-plus',
                    'cheapest'  => 'qwen-flash',
                    'smartest'  => 'qwen-max',
                ],
            ],
        ],
        // Moonshot's Kimi API is also OpenAI-compatible.
        'moonshot' => [
            'driver' => 'openai-compatible',
            'key'    => env('MOONSHOT_API_KEY'),
            'url'    => 'https://api.moonshot.ai/v1',
            'stream_options' => ['include_usage' => true],
            'models' => [
                'text' => [
                    'default'   => 'kimi-k2.6',
                    'cheapest'  => 'kimi-k2.5',
                    'smartest'  => 'kimi-k3',
                ],
            ],
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
