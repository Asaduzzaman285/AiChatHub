<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe-only for now (bKash has no saved-token/re-charge equivalent in this
 * codebase — deferred to Phase 2, see HANDOFF.md). One row per user.
 * payment_method_id is a bare UUID, not an FK — it references a row in
 * payment-service's own database, a different service/schema, resolved via
 * an internal HTTP call at charge time rather than a cross-service FK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_debit_settings', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->decimal('threshold_usd', 10, 2)->default(1.00);
            $table->decimal('topup_amount_usd', 10, 2)->default(10.00);
            $table->uuid('payment_method_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_debit_settings');
    }
};
