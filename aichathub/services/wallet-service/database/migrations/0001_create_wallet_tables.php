<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id')->unique();
            $table->decimal('balance', 12, 6)->default(0);
            $table->decimal('reserved_balance', 12, 6)->default(0);
            $table->decimal('credit_balance', 12, 6)->default(0);       // negative = owes
            $table->decimal('credit_limit', 10, 2)->default(3.00);
            $table->string('currency', 3)->default('USD');
            $table->timestamps();

            // Check constraints — prevent impossible states
            $table->index('user_id');
        });

        // DB-level constraints — application layer cannot bypass these
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_balance_non_negative CHECK (balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_reserved_non_negative CHECK (reserved_balance >= 0)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_credit_within_limit CHECK (credit_balance >= -(credit_limit))');

        // Partial index for monitoring low-balance wallets
        DB::statement('CREATE INDEX idx_wallet_low_balance ON wallets (balance) WHERE balance < 5.0');

        Schema::create('wallet_ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('type', 20);                   // credit | debit | refund | credit_recovery | admin_adjustment
            $table->decimal('amount', 12, 6);
            $table->decimal('balance_before', 12, 6);
            $table->decimal('balance_after', 12, 6);
            $table->string('description', 255);
            $table->string('reference_type', 50)->nullable();  // transaction | usage_log | subscription
            $table->uuid('reference_id')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->timestamp('created_at')->useCurrent();     // NO updated_at — append-only

            $table->index(['wallet_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        // Revoke UPDATE/DELETE from wallet_app user — ledger must be append-only
        DB::statement('REVOKE UPDATE, DELETE ON wallet_ledger_entries FROM wallet_app');

        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('type', 30);                   // credit_used | credit_recovered
            $table->decimal('amount', 12, 6);
            $table->decimal('credit_balance_before', 12, 6);
            $table->decimal('credit_balance_after', 12, 6);
            $table->string('description', 255);
            $table->uuid('reference_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement('REVOKE UPDATE, DELETE ON credit_ledger FROM wallet_app');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
        Schema::dropIfExists('wallet_ledger_entries');
        Schema::dropIfExists('wallets');
    }
};
