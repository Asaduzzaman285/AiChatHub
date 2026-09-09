<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserRegistered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param ?string $origin The registering browser's Origin header (e.g.
     *   https://staging.alveta.ai), so the eventual email-verification click
     *   — possibly days later, with no Origin header of its own — can be
     *   redirected back to the same frontend. Null for paths with no
     *   meaningful origin of their own (Google OAuth auto-registration,
     *   which doesn't need this redirect at all) or a resend with none sent.
     */
    public function __construct(public readonly User $user, public readonly ?string $origin = null) {}
}
