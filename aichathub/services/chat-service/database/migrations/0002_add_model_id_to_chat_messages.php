<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supports switching models mid-conversation: chat_sessions.model_id alone can only
 * describe one model for the session's whole lifetime, but the actual AI call has
 * always accepted model_id per /chat/stream request, independent of the session. This
 * records which model actually produced each assistant message, so history stays
 * accurate even after switching. chat_sessions.model_id is kept as "the most recently
 * used model" (updated on each assistant message) for sidebar/header display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->uuid('model_id')->nullable()->after('role'); // Reference to ai_svc.ai_models — null for user/system messages
            $table->index('model_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('model_id');
        });
    }
};
