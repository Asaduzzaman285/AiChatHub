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
            ['provider' => 'gemini',    'name' => 'Gemini 1.5 Flash',   'model_id' => 'gemini-1.5-flash',              'type' => 'text', 'context_window' => 1000000,'tier' => 'basic'],
            ['provider' => 'xai',       'name' => 'Grok Beta',          'model_id' => 'grok-beta',                     'type' => 'text', 'context_window' => 131072, 'tier' => 'basic'],

            // Standard tier additional models
            ['provider' => 'openai',    'name' => 'GPT-4o',             'model_id' => 'gpt-4o',                        'type' => 'text', 'context_window' => 128000, 'tier' => 'standard'],
            ['provider' => 'anthropic', 'name' => 'Claude 3.5 Sonnet',  'model_id' => 'claude-3-5-sonnet-20241022',    'type' => 'text', 'context_window' => 200000, 'tier' => 'standard'],
            ['provider' => 'gemini',    'name' => 'Gemini 1.5 Pro',     'model_id' => 'gemini-1.5-pro',                'type' => 'text', 'context_window' => 2000000,'tier' => 'standard'],

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
                'vision'           => in_array($model['model_id'], ['gpt-4o', 'gemini-1.5-pro', 'claude-3-5-sonnet-20241022']),
                'file_upload'      => in_array($model['model_id'], ['gpt-4o', 'claude-3-5-sonnet-20241022', 'gemini-1.5-pro']),
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

        // Seed pricing (GPT-4o as example)
        $gpt4oId = DB::table('ai_models')->where('model_id', 'gpt-4o')->value('id');
        if ($gpt4oId) {
            DB::table('model_pricing')->updateOrInsert(
                ['model_id' => $gpt4oId, 'pricing_type' => 'token_based'],
                ['input_rate_per_million' => 2.50, 'output_rate_per_million' => 10.00, 'is_active' => true, 'effective_from' => now()]
            );
        }

        $this->command->info('AI models seeded. Update subscription package model_access with these IDs.');
        $this->command->info('Basic model IDs: ' . implode(', ', $basicModelIds));
        $this->command->info('Standard model IDs: ' . implode(', ', $standardModelIds));
        $this->command->info('Pro model IDs: ' . implode(', ', $proModelIds));
    }
}
