<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog of currencies the platform is willing to display/accept — adding
        // a new one later is an admin-created row here, never a schema change.
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('code', 3)->unique(); // ISO 4217 — USD, BDT, EUR, ...
            $table->string('name', 100);
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Versioned conversion policy, one row per currency per period — mirrors
        // ai-gateway-service's model_pricing exactly (effective_from/until,
        // is_active, never mutated in place on change — see CurrencyAdminController
        // for why: closing the old row and inserting a new one instead of updating
        // means every past transaction's own already-stored exchange_rate snapshot
        // stays meaningful, and the rate history itself stays auditable).
        //
        // Broken into named components rather than one opaque "markup_percentage"
        // because that's the actual real-world shape of the policy (confirmed
        // directly from the business's own rate sheet): a raw market exchange
        // rate, then margin/tax/VAT/withholding-tax stacked on top as separate,
        // independently adjustable percentages — not one blended number nobody
        // can audit later. effective_rate is the one number everything else in the
        // platform actually multiplies a USD amount by; the components exist so an
        // admin can see and adjust each piece of *why* it is what it is.
        Schema::create('currency_rates', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('currency_code', 3);
            $table->decimal('exchange_rate', 12, 6);
            $table->decimal('margin_percentage', 6, 2)->default(0);
            $table->decimal('tax_percentage', 6, 2)->default(0);
            $table->decimal('vat_percentage', 6, 2)->default(0);
            $table->decimal('withholding_tax_percentage', 6, 2)->default(0);
            // effective_rate = exchange_rate * (1 + (margin+tax+vat+withholding)/100)
            // — computed and stored at write time (see CurrencyAdminController), not
            // derived on every read, so it's the one column every consumer needs.
            $table->decimal('effective_rate', 12, 6);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->index(['currency_code', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
        Schema::dropIfExists('currencies');
    }
};
