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

        $verifyUrl = config('services.api_public_url') . '/api/v1/auth/verify/' . $token;

        Mail::send([], [], function ($message) use ($user, $verifyUrl) {
            $message->to($user->email, $user->name)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject('Verify your Alveta.ai account')
                ->html(view('emails.verify-account', [
                    'name'      => $user->name,
                    'verifyUrl' => $verifyUrl,
                ])->render());
        });

        // ── 2. Wallet creation is handled by RegisterController via afterResponse ──
        // Nothing needed here — wallet HTTP call is dispatched after HTTP response
        // to avoid blocking the registration request.
    }
}
