<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The disappearing-chat timer for private chats. expires_at is set once at creation
 * (SessionController::store()) and never extended or changed — this sweep just finds
 * whichever private sessions have crossed that point and soft-deletes them via the
 * same SoftDeletes mechanism SessionController::destroy() already uses for manual
 * deletion, so nothing new is introduced on the deletion side, only on discovery.
 */
class ExpirePrivateChatSessionsCommand extends Command
{
    protected $signature   = 'chat:expire-private-sessions';
    protected $description = 'Soft-delete private chat sessions whose disappearing timer has elapsed';

    public function handle(): void
    {
        $expired = ChatSession::where('is_private', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No expired private chat sessions.');
            return;
        }

        $deleted = 0;

        foreach ($expired as $session) {
            try {
                $session->delete();
                $deleted++;
            } catch (\Throwable $e) {
                Log::error('Failed to expire private chat session', [
                    'session_id' => $session->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->info("Expired {$deleted}/{$expired->count()} private chat session(s).");
    }
}
