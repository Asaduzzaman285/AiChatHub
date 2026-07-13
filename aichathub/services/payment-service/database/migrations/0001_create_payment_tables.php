<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('gateway', 50);        // stripe | bkash | nagad | sslcommerz | paypal
            $table->string('type', 50);           // card | mobile_banking | bank_transfer
            $table->text('token');                // Opaque gateway token — NEVER raw card data
            $table->string('last_four', 4)->nullable();
            $table->string('card_brand', 30)->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('mobile_number', 20)->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('user_id');
            $table->index(['user_id', 'is_default', 'is_active']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('type', 50);           // subscription_purchase | wallet_topup | refund
            $table->string('status', 50)->default('pending'); // pending | completed | failed | refunded
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->string('gateway', 50);
            $table->string('gateway_reference', 255)->nullable(); // Gateway's own txn ID
            $table->uuid('payment_method_id')->nullable();
            $table->string('idempotency_key', 255)->unique();     // Prevent double-charges
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('gateway_fee', 10, 2)->nullable();
            $table->decimal('net_amount', 10, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['gateway', 'gateway_reference']);
            $table->index('status');
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('gateway', 50);
            $table->string('event_type', 100);
            $table->string('gateway_reference', 255);    // Gateway's event ID
            $table->string('status', 30)->default('pending'); // pending | processed | failed
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('created_at')->useCurrent();

            // This unique constraint IS the idempotency guard for webhooks
            $table->unique(['gateway', 'gateway_reference']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('payment_methods');
    }
};
