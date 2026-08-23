<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Private chats: is_private is set once at creation and is never changed afterward
 * (enforced in SessionController::update() via a `prohibited` validation rule, not just
 * omitted from the UI). expires_at is the disappearing-chat timer — a whole-session
 * auto soft-delete, not per-message like WhatsApp's actual disappearing messages —
 * swept by the chat:expire-private-sessions scheduled command, which reuses the
 * existing SoftDeletes mechanism ChatSession::delete() already provides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->boolean('is_private')->default(false)->after('status');
            $table->timestamp('expires_at')->nullable()->after('is_private');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['is_private', 'expires_at']);
        });
    }
};
