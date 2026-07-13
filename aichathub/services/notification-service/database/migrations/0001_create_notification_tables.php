<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->string('type', 100);              // welcome | email_verification | subscription_receipt | etc.
            $table->string('channel', 30);            // email | sms | push | in_app
            $table->string('subject', 255)->nullable();
            $table->text('content');
            $table->jsonb('metadata')->nullable();
            $table->string('status', 30)->default('pending'); // pending | sent | delivered | failed | bounced
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            $table->string('idempotency_key', 255)->nullable()->unique(); // Prevent duplicate sends
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('type');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id')->unique();
            $table->boolean('email_subscription_events')->default(true);
            $table->boolean('email_payment_events')->default(true);
            $table->boolean('email_low_balance')->default(true);
            $table->boolean('email_marketing')->default(false);
            $table->boolean('sms_critical_alerts')->default(false);
            $table->boolean('push_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
