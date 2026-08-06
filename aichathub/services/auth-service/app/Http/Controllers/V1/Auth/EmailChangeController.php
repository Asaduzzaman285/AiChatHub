<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailChangeController extends Controller
{
    /**
     * POST /api/v1/auth/email/change
     * The new address is never applied here — it only takes effect once the
     * confirmation link sent to it is clicked (EmailVerificationController::
     * verify()). Mirrors PasswordResetController::setPassword()'s
     * current_password gate exactly, and EmailVerification's existing
     * token/expiry mechanism, rather than building either from scratch.
     */
    public function request(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Please verify your current email before requesting a change.',
                'error'   => 'account_not_active',
            ], 422);
        }

        $rules = [
            'new_email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ];
        if ($user->hasPassword()) {
            $rules['current_password'] = ['required', 'string'];
        }

        $data = $request->validate($rules);

        if ($user->hasPassword() && ! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
                'error'   => 'invalid_current_password',
            ], 422);
        }

        // Only clears prior pending *change* requests — never touches a still-pending
        // original-registration verification row (which can't coexist here anyway,
        // since the account_not_active check above already requires status === 'active').
        EmailVerification::where('user_id', $user->id)
            ->where('used', false)
            ->whereNotNull('new_email')
            ->delete();

        $token = Str::random(64);

        $verification = EmailVerification::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'new_email'  => $data['new_email'],
            'used'       => false,
            'expires_at' => now()->addHours(24),
        ]);

        $verifyUrl = config('app.url').'/api/v1/auth/verify/'.$token;
        $newEmail  = $data['new_email'];
        $name      = $user->name;

        try {
            Mail::send([], [], function ($message) use ($newEmail, $name, $verifyUrl) {
                $message->to($newEmail, $name)
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject('Confirm your new email address')
                    ->html("
                        <h2>Confirm your new email address</h2>
                        <p>Hi {$name}, click below to confirm <strong>{$newEmail}</strong> as your new AI ChatHub sign-in email.</p>
                        <p>
                            <a href='{$verifyUrl}'
                               style='background:#4F46E5;color:white;padding:12px 24px;
                                      text-decoration:none;border-radius:6px;display:inline-block;'>
                                Confirm New Email
                            </a>
                        </p>
                        <p>Or copy this link: <br><code>{$verifyUrl}</code></p>
                        <p>This link expires in 24 hours. If you didn't request this change, you can safely ignore this email — your sign-in email will not change.</p>
                    ");
            });
        } catch (\Throwable $e) {
            // \Throwable, not \Exception — mirrors RegisterController's wallet-create
            // call, same reasoning: a transport-level failure can surface as a type
            // \Exception alone won't catch. Delete the now-orphaned, undeliverable
            // row rather than leaving it pending with no way for the user to confirm it.
            Log::error('Email-change confirmation send failed: '.$e->getMessage(), ['user_id' => $user->id]);
            $verification->delete();

            return response()->json([
                'message' => "We couldn't send the confirmation email right now. Please try again in a moment.",
                'error'   => 'send_failed',
            ], 502);
        }

        return response()->json([
            'message' => "We sent a confirmation link to {$newEmail}. Click it to complete the change.",
        ]);
    }
}
