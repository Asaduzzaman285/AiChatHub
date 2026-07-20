<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name'                     => 'Basic',
                'slug'                     => 'basic',
                'description'              => 'Perfect for getting started with AI assistance.',
                'monthly_price_usd'        => 10.00,
                'monthly_price_bdt'        => 1100.00,
                'monthly_wallet_credit_usd'=> 10.00,
                'model_access'             => json_encode(['gemini-2.5-flash', 'gpt-4o-mini', 'deepseek-v4-flash']),
                'features'                 => json_encode([
                    'file_upload'   => false,
                    'api_access'    => false,
                    'comparison'    => false,
                    'image_gen'     => false,
                    'audio'         => false,
                    'vision'        => false,
                ]),
                'is_active'  => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'                     => 'Standard',
                'slug'                     => 'standard',
                'description'              => 'For professionals who need more power and models.',
                'monthly_price_usd'        => 20.00,
                'monthly_price_bdt'        => 2200.00,
                'monthly_wallet_credit_usd'=> 20.00,
                'model_access'             => json_encode([
                    'gemini-2.5-flash', 'gpt-4o-mini', 'deepseek-v4-flash',
                    'gemini-2.5-pro', 'gpt-4o', 'claude-3-haiku-20240307', 'deepseek-v4-pro',
                ]),
                'features'                 => json_encode([
                    'file_upload'   => true,
                    'api_access'    => false,
                    'comparison'    => true,
                    'image_gen'     => false,
                    'audio'         => false,
                    'vision'        => true,
                ]),
                'is_active'  => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name'                     => 'Pro',
                'slug'                     => 'pro',
                'description'              => 'Unlimited models, image generation, audio, and API access.',
                'monthly_price_usd'        => 40.00,
                'monthly_price_bdt'        => 4400.00,
                'monthly_wallet_credit_usd'=> 40.00,
                'model_access'             => json_encode([
                    'gemini-2.5-flash', 'gpt-4o-mini', 'deepseek-v4-flash',
                    'gemini-2.5-pro', 'gpt-4o', 'claude-3-haiku-20240307', 'deepseek-v4-pro',
                    'gpt-4-turbo', 'claude-3-5-sonnet-20241022', 'claude-3-opus-20240229', 'grok-beta',
                    'dall-e-3', 'whisper-1', 'eleven_turbo_v2_5',
                ]),
                'features'                 => json_encode([
                    'file_upload'   => true,
                    'api_access'    => true,
                    'comparison'    => true,
                    'image_gen'     => true,
                    'audio'         => true,
                    'vision'        => true,
                ]),
                'is_active'  => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($packages as $pkg) {
            DB::table('packages')->updateOrInsert(['slug' => $pkg['slug']], $pkg);
        }

        $this->command->info('Packages seeded.');
    }
}
