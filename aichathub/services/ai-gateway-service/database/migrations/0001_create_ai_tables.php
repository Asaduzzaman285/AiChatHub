<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->string('provider', 50);
            $table->string('name', 100);
            $table->string('model_id', 100);          // e.g. 'gpt-4o'
            $table->string('type', 50);               // text | image_generation | audio_tts | audio_stt | embedding
            $table->text('description')->nullable();
            $table->integer('context_window')->nullable();
            $table->integer('max_output_tokens')->nullable();
            $table->jsonb('capabilities')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['provider', 'model_id']);
            $table->index(['provider', 'is_active']);
            $table->index('type');
        });

        Schema::create('model_pricing', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->string('pricing_type', 30);       // token_based | flat_per_image | character_based | per_minute
            $table->decimal('input_rate_per_million', 10, 6)->nullable();
            $table->decimal('output_rate_per_million', 10, 6)->nullable();
            $table->decimal('flat_rate_per_unit', 10, 4)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['model_id', 'is_active']);
        });

        Schema::create('usage_logs', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('session_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->foreignUuid('model_id')->constrained('ai_models');
            $table->string('operation_type', 50);     // text_chat | image_generation | audio_tts | audio_stt
            $table->string('status', 30)->default('completed'); // completed | failed | refunded
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('estimated_cost', 12, 6)->default(0);
            $table->decimal('actual_cost', 12, 6)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->integer('duration_ms')->nullable();
            $table->string('provider_request_id', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['model_id', 'created_at']);
            $table->index('session_id');
            $table->index('status');
        });

        // Append-only: revoke UPDATE/DELETE from ai_app
        DB::statement('REVOKE UPDATE, DELETE ON usage_logs FROM ai_app');

        Schema::create('provider_fallback_rules', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('primary_model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->foreignUuid('fallback_model_id')->constrained('ai_models')->cascadeOnDelete();
            $table->jsonb('trigger_conditions')->default('{}');
            $table->integer('priority')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['primary_model_id', 'fallback_model_id']);
            $table->index(['primary_model_id', 'is_active']);
        });

        Schema::create('circuit_breaker_state', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('model_id')->unique()->constrained('ai_models')->cascadeOnDelete();
            $table->string('state', 20)->default('closed'); // closed | open | half_open
            $table->integer('failure_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('next_probe_at')->nullable();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circuit_breaker_state');
        Schema::dropIfExists('provider_fallback_rules');
        Schema::dropIfExists('usage_logs');
        Schema::dropIfExists('model_pricing');
        Schema::dropIfExists('ai_models');
    }
};
