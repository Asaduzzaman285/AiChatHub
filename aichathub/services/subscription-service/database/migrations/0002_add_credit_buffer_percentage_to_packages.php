<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Percentage of monthly_price_usd used as this package's wallet
            // credit_limit (the "don't cut a request off mid-stream" buffer —
            // see wallet_svc.wallets.credit_limit/credit_balance) once a user
            // subscribes/upgrades/renews into it. Admin-editable via
            // PATCH /packages/{id}. Default matches the flat $3 buffer every
            // user got before this — 30% of the cheapest ($10) package.
            $table->decimal('credit_buffer_percentage', 5, 2)->default(30.00)->after('monthly_wallet_credit_usd');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('credit_buffer_percentage');
        });
    }
};
