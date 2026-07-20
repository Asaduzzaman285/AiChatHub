<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            // Basic tier models
            ['provider' => 'openai',    'name' => 'GPT-4o Mini',        'model_id' => 'gpt-4o-mini',                  'type' => 'text', 'context_window' => 128000, 'tier' => 'basic'],
            ['provider' => 'anthropic', 'name' => 'Claude 3 Haiku',     'model_id' => 'claude-3-haiku-20240307',       'type' => 'text', 'context_window' => 200000, 'tier' => 'basic'],
            ['provider' => 'gemini',    'name' => 'Gemini 2.5 Flash',   'model_id' => 'gemini-2.5-flash',              'type' => 'text', 'context_window' => 1000000,'tier' => 'basic'],
            // grok-beta is UNVERIFIED — xAI's /v1/models couldn't be checked (account has
            // zero credits, 403s on everything including listing). Re-check this model_id
            // against https://api.x.ai/v1/models once billing is set up, same way
            // gemini-1.5-* turned out to be stale — see 2026-07-19 session notes.
            ['provider' => 'xai',       'name' => 'Grok Beta',          'model_id' => 'grok-beta',                     'type' => 'text', 'context_window' => 131072, 'tier' => 'basic'],
            ['provider' => 'deepseek',  'name' => 'DeepSeek V4 Flash',  'model_id' => 'deepseek-v4-flash',             'type' => 'text', 'context_window' => 128000, 'tier' => 'basic'],

            // Standard tier additional models
            ['provider' => 'openai',    'name' => 'GPT-4o',             'model_id' => 'gpt-4o',                        'type' => 'text', 'context_window' => 128000, 'tier' => 'standard'],
            ['provider' => 'anthropic', 'name' => 'Claude 3.5 Sonnet',  'model_id' => 'claude-3-5-sonnet-20241022',    'type' => 'text', 'context_window' => 200000, 'tier' => 'standard'],
            ['provider' => 'gemini',    'name' => 'Gemini 2.5 Pro',     'model_id' => 'gemini-2.5-pro',                'type' => 'text', 'context_window' => 1048576,'tier' => 'standard'],
            ['provider' => 'deepseek',  'name' => 'DeepSeek V4 Pro',    'model_id' => 'deepseek-v4-pro',               'type' => 'text', 'context_window' => 128000, 'tier' => 'standard'],

            // Pro tier additional models
            ['provider' => 'openai',    'name' => 'GPT-4 Turbo',        'model_id' => 'gpt-4-turbo',                   'type' => 'text', 'context_window' => 128000, 'tier' => 'pro'],
            ['provider' => 'anthropic', 'name' => 'Claude 3 Opus',      'model_id' => 'claude-3-opus-20240229',        'type' => 'text', 'context_window' => 200000, 'tier' => 'pro'],
            ['provider' => 'openai',    'name' => 'DALL-E 3',           'model_id' => 'dall-e-3',                      'type' => 'image_generation', 'context_window' => null, 'tier' => 'pro'],
            ['provider' => 'elevenlabs','name' => 'ElevenLabs Turbo',   'model_id' => 'eleven_turbo_v2_5',             'type' => 'audio_tts', 'context_window' => null, 'tier' => 'pro'],
            ['provider' => 'openai',    'name' => 'Whisper',            'model_id' => 'whisper-1',                     'type' => 'audio_stt', 'context_window' => null, 'tier' => 'pro'],
        ];

        $basicModelIds    = [];
        $standardModelIds = [];
        $proModelIds      = [];

        foreach ($models as $model) {
            $tier = $model['tier'];
            unset($model['tier']);

            $model['capabilities'] = json_encode([
                'streaming'        => in_array($model['type'], ['text']),
                'function_calling' => in_array($model['model_id'], ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo']),
                'vision'           => in_array($model['model_id'], ['gpt-4o', 'gemini-2.5-pro', 'claude-3-5-sonnet-20241022']),
                'file_upload'      => in_array($model['model_id'], ['gpt-4o', 'claude-3-5-sonnet-20241022', 'gemini-2.5-pro']),
            ]);
            $model['is_active']  = true;
            $model['created_at'] = now();
            $model['updated_at'] = now();

            $existing = DB::table('ai_models')->where('provider', $model['provider'])->where('model_id', $model['model_id'])->first();
            if (! $existing) {
                DB::table('ai_models')->insert($model);
            }
            $id = DB::table('ai_models')->where('model_id', $model['model_id'])->value('id');

            if ($tier === 'basic')    $basicModelIds[]    = $id;
            if ($tier === 'standard') $standardModelIds[] = $id;
            if ($tier === 'pro')      $proModelIds[]      = $id;
        }

        // Seed pricing for every text model — CostTrackingMiddleware falls back to the
        // GPT-4o rate for anything missing here, which understates cost for cheaper
        // models (e.g. Gemini Flash) and overstates it for pricier ones. Rates are
        // approximate published per-1M-token list prices, USD, for internal wallet
        // cost simulation only — not fetched live, re-check before relying on them
        // for real billing.
        $textPricing = [
            'gpt-4o'                     => ['input' => 2.50,  'output' => 10.00],
            'gpt-4o-mini'                 => ['input' => 0.15,  'output' => 0.60],
            'gpt-4-turbo'                 => ['input' => 10.00, 'output' => 30.00],
            'claude-3-5-sonnet-20241022'  => ['input' => 3.00,  'output' => 15.00],
            'claude-3-opus-20240229'      => ['input' => 15.00, 'output' => 75.00],
            'claude-3-haiku-20240307'     => ['input' => 0.25,  'output' => 1.25],
            'gemini-2.5-pro'              => ['input' => 1.25,  'output' => 5.00],
            'gemini-2.5-flash'            => ['input' => 0.075, 'output' => 0.30],
            'grok-beta'                   => ['input' => 5.00,  'output' => 15.00],
            'deepseek-v4-flash'           => ['input' => 0.28,  'output' => 0.42],
            'deepseek-v4-pro'             => ['input' => 0.56,  'output' => 1.68],
        ];

        foreach ($textPricing as $modelId => $rates) {
            $id = DB::table('ai_models')->where('model_id', $modelId)->value('id');
            if (! $id) {
                continue;
            }
            DB::table('model_pricing')->updateOrInsert(
                ['model_id' => $id, 'pricing_type' => 'token_based'],
                ['input_rate_per_million' => $rates['input'], 'output_rate_per_million' => $rates['output'], 'is_active' => true, 'effective_from' => now()]
            );
        }

        $this->command->info('AI models seeded. Update subscription package model_access with these IDs.');
        $this->command->info('Basic model IDs: ' . implode(', ', $basicModelIds));
        $this->command->info('Standard model IDs: ' . implode(', ', $standardModelIds));
        $this->command->info('Pro model IDs: ' . implode(', ', $proModelIds));
    }
}
