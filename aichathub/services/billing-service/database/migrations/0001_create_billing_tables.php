<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('subscription_id')->nullable();
            $table->string('invoice_number', 50)->unique();
            $table->string('type', 50);               // subscription_purchase | subscription_renewal | subscription_upgrade
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('tax_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->string('status', 30)->default('generated');
            $table->uuid('transaction_id')->nullable();
            $table->text('pdf_url')->nullable();
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('receipt_number', 50)->unique();
            $table->string('type', 50);               // wallet_topup | refund
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->uuid('transaction_id')->nullable();
            $table->text('pdf_url')->nullable();
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('promo_codes', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('code', 50)->unique();
            $table->string('type', 30);               // flat_discount | percent_discount | free_wallet_credit
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->integer('max_uses')->nullable();
            $table->integer('times_used')->default(0);
            $table->timestamp('valid_from');
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('user_promo_usage', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->uuid('transaction_id')->nullable();
            $table->timestamp('applied_at')->useCurrent();

            $table->unique(['promo_code_id', 'user_id']);   // Prevent double-use per user
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_promo_usage');
        Schema::dropIfExists('promo_codes');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('invoices');
    }
};
