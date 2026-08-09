<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets sell rates be computed (provider cost * (1 + markup%)) instead of
 * admin-typed directly — see AiModelAdminController::createPricing(). The
 * existing input_rate_per_million/output_rate_per_million/flat_rate_per_unit
 * columns stay exactly as-is and remain what CostTrackingMiddleware actually
 * bills from; this migration only adds the cost basis they're now derived
 * from, it doesn't touch the live charging path at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_pricing', function (Blueprint $table) {
            $table->decimal('provider_input_rate_per_million', 10, 6)->nullable();
            $table->decimal('provider_output_rate_per_million', 10, 6)->nullable();
            $table->decimal('provider_flat_rate_per_unit', 10, 4)->nullable();
            $table->decimal('markup_percentage', 5, 2)->nullable();
        });

        // Backfill existing (seeded) rows so the admin UI shows a coherent baseline
        // instead of blank cost/markup fields — 0% markup means provider cost = sell
        // rate = whatever it already billed at, so this changes nothing about what
        // any model actually charges today.
        DB::table('model_pricing')->where('is_active', true)->update([
            'provider_input_rate_per_million'  => DB::raw('input_rate_per_million'),
            'provider_output_rate_per_million' => DB::raw('output_rate_per_million'),
            'provider_flat_rate_per_unit'      => DB::raw('flat_rate_per_unit'),
            'markup_percentage'                => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('model_pricing', function (Blueprint $table) {
            $table->dropColumn([
                'provider_input_rate_per_million',
                'provider_output_rate_per_million',
                'provider_flat_rate_per_unit',
                'markup_percentage',
            ]);
        });
    }
};
