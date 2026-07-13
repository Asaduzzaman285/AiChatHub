<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('model_id');                            // Reference to ai_svc.ai_models
            $table->string('title', 255)->default('New Chat');
            $table->string('status', 30)->default('active');    // active | archived
            $table->integer('message_count')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('total_cost', 12, 6)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'updated_at']);
            $table->index('model_id');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->foreignUuid('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('role', 20);                          // user | assistant | system | tool
            $table->text('content');
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->uuid('usage_log_id')->nullable();            // Reference to ai_svc.usage_logs
            $table->string('provider_message_id', 255)->nullable();
            $table->boolean('is_streaming')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();       // Append-only, no updated_at

            $table->index(['session_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('file_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v4()'));
            $table->uuid('user_id');
            $table->uuid('session_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->string('file_name', 255);
            $table->string('original_name', 255);
            $table->bigInteger('file_size');
            $table->string('mime_type', 100);
            $table->string('storage_disk', 50)->default('s3');
            $table->text('storage_path');
            $table->text('storage_url')->nullable();
            $table->string('virus_scan_status', 30)->default('pending'); // pending|clean|infected
            $table->timestamp('virus_scan_at')->nullable();
            $table->string('provider_file_id', 255)->nullable();         // AI SDK Files API ID
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('session_id');
            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_attachments');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_sessions');
    }
};
