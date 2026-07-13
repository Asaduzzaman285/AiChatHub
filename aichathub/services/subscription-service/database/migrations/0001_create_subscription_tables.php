<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->decimal('monthly_price_usd', 10, 2);
            $table->decimal('monthly_price_bdt', 10, 2)->nullable();
            $table->decimal('monthly_wallet_credit_usd', 10, 2);
            $table->jsonb('model_access')->default('[]');
            $table->jsonb('features')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->foreignUuid('package_id')->constrained('packages');
            $table->uuid('previous_package_id')->nullable();
            $table->uuid('scheduled_package_id')->nullable();
            $table->uuid('payment_method_id')->nullable();
            $table->string('status', 50)->default('active');
            $table->boolean('auto_renew')->default(true);
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 10, 6)->default(1.000000);
            $table->timestamp('activated_at')->useCurrent();
            $table->timestamp('renews_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('past_due_at')->nullable();
            $table->timestamps();

            // Enforce one active subscription per user at DB level
            $table->index('user_id');
            $table->index('status');
            $table->index(['renews_at', 'auto_renew']);
        });

        // Partial unique index — only one active/past_due subscription per user
        DB::statement("
            CREATE UNIQUE INDEX idx_sub_user_one_active
            ON user_subscriptions (user_id)
            WHERE status IN ('active','past_due')
        ");

        Schema::create('subscription_history', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('subscription_id')->constrained('user_subscriptions');
            $table->uuid('user_id');
            $table->string('action', 50);
            $table->uuid('old_package_id')->nullable();
            $table->uuid('new_package_id')->nullable();
            $table->string('old_status', 50)->nullable();
            $table->string('new_status', 50)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('renewal_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('subscription_id')->constrained('user_subscriptions');
            $table->uuid('user_id');
            $table->integer('attempt_number');
            $table->timestamp('scheduled_at');
            $table->timestamp('attempted_at')->nullable();
            $table->boolean('success')->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subscription_id']);
            $table->index(['scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_attempts');
        Schema::dropIfExists('subscription_history');
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('packages');
    }
};
