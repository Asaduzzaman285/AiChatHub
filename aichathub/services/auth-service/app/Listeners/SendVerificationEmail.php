<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Models\EmailVerification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendVerificationEmail implements ShouldQueue
{
    public function handle(UserRegistered $event): void
    {
        $user = $event->user;

        // ── 1. Send email verification ────────────────────────────────
        EmailVerification::where('user_id', $user->id)
            ->where('used', false)
            ->delete();

        $token = Str::random(64);

        EmailVerification::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'used'       => false,
            'expires_at' => now()->addHours(24),
        ]);

        $verifyUrl = config('app.url') . '/api/v1/auth/verify/' . $token;

        Mail::send([], [], function ($message) use ($user, $verifyUrl) {
            $message->to($user->email, $user->name)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject('Verify your AI ChatHub account')
                ->html("
                    <h2>Welcome to AI ChatHub, {$user->name}!</h2>
                    <p>Please verify your email address to activate your account.</p>
                    <p>
                        <a href='{$verifyUrl}'
                           style='background:#4F46E5;color:white;padding:12px 24px;
                                  text-decoration:none;border-radius:6px;display:inline-block;'>
                            Verify Email Address
                        </a>
                    </p>
                    <p>Or copy this link: <br><code>{$verifyUrl}</code></p>
                    <p>This link expires in 24 hours.</p>
                ");
        });

        // ── 2. Wallet creation is handled by RegisterController via afterResponse ──
        // Nothing needed here — wallet HTTP call is dispatched after HTTP response
        // to avoid blocking the registration request.
    }
}
