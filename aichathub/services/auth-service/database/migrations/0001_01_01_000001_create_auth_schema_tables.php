<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // All tables live in auth_svc schema (set via search_path in database.php)

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();  // NULL for Google-only accounts
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('status', 50)->default('pending_verification');
            $table->string('preferred_currency', 3)->default('USD');
            $table->text('avatar_url')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 50);               // 'google', 'github', etc.
            $table->string('provider_user_id', 255);      // Google's "sub" field
            $table->text('access_token')->nullable();     // Encrypted at rest
            $table->text('refresh_token')->nullable();    // Encrypted at rest
            $table->timestamp('token_expires_at')->nullable();
            $table->text('avatar_url')->nullable();
            $table->jsonb('raw_data')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->index('user_id');
        });

        Schema::create('email_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
        });

        Schema::create('password_resets', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->boolean('used')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
        });

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 255)->unique();
            $table->boolean('revoked')->default(false);
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('email', 255);
            $table->string('ip_address', 45);
            $table->boolean('success');
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['email', 'created_at']);
            $table->index(['ip_address', 'created_at']);
        });

        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50)->default('admin');
            $table->jsonb('permissions')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('actor_type', 30)->default('admin');
            $table->string('action', 100);
            $table->string('resource_type', 50);
            $table->uuid('resource_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['admin_user_id', 'created_at']);
            $table->index(['resource_type', 'resource_id']);
        });

        Schema::create('system_config', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value');
            $table->text('description')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });

        // Seed default system config values
        DB::table('system_config')->insert([
            ['key' => 'credit_buffer_default',    'value' => '3.00',   'description' => 'Default max credit buffer in USD'],
            ['key' => 'low_balance_threshold',     'value' => '5.00',   'description' => 'Wallet balance for low-balance alert'],
            ['key' => 'critical_balance_threshold','value' => '1.00',   'description' => 'Wallet balance for critical alert'],
            ['key' => 'renewal_retry_1_hours',     'value' => '24',     'description' => 'Hours before first renewal retry'],
            ['key' => 'renewal_retry_2_hours',     'value' => '48',     'description' => 'Hours before second renewal retry'],
            ['key' => 'renewal_retry_3_hours',     'value' => '72',     'description' => 'Hours before third renewal retry'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_config');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('email_verifications');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('users');
    }
};
