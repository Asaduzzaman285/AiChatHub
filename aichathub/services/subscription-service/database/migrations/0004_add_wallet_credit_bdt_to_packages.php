<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Mirrors monthly_price_bdt's own pattern: an independent,
            // admin-typed BDT figure, not derived from monthly_wallet_credit_usd
            // by any formula. A bKash-funded purchase credits this value
            // (converted once via the active currency policy into the wallet's
            // real USD ledger amount); a card-funded purchase still credits
            // monthly_wallet_credit_usd directly. Nullable — a package with no
            // BDT credit configured falls back to crediting the USD amount
            // even for a bKash purchase, so this never regresses to $0. See
            // PackageActivationService::computeWalletCredit().
            $table->decimal('monthly_wallet_credit_bdt', 10, 2)->nullable()->after('monthly_wallet_credit_usd');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('monthly_wallet_credit_bdt');
        });
    }
};
