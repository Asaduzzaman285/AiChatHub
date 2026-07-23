<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * POST /api/v1/auth/password/forgot
     * Always returns the same generic message regardless of whether the email
     * exists — same reasoning as EmailVerificationController::resend(), don't
     * let this endpoint be used to enumerate registered addresses.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->email)->first();

        if ($user) {
            // Throttle: max one reset email per 2 minutes, same window as
            // the verification-resend endpoint.
            $recent = PasswordReset::where('user_id', $user->id)
                ->where('used', false)
                ->where('created_at', '>=', now()->subMinutes(2))
                ->exists();

            if (! $recent) {
                PasswordReset::where('user_id', $user->id)->where('used', false)->delete();

                $token = Str::random(64);

                PasswordReset::create([
                    'user_id'    => $user->id,
                    'token'      => $token,
                    'used'       => false,
                    'expires_at' => now()->addHours(2),
                ]);

                $resetUrl = rtrim((string) config('services.frontend_url'), '/')."/reset-password?token={$token}";

                Mail::send([], [], function ($message) use ($user, $resetUrl) {
                    $message->to($user->email, $user->name)
                        ->from(config('mail.from.address'), config('mail.from.name'))
                        ->subject('Reset your AI ChatHub password')
                        ->html("
                            <h2>Password reset requested</h2>
                            <p>Hi {$user->name}, we received a request to reset your AI ChatHub password.</p>
                            <p>
                                <a href='{$resetUrl}'
                                   style='background:#4F46E5;color:white;padding:12px 24px;
                                          text-decoration:none;border-radius:6px;display:inline-block;'>
                                    Reset Password
                                </a>
                            </p>
                            <p>Or copy this link: <br><code>{$resetUrl}</code></p>
                            <p>This link expires in 2 hours. If you didn't request this, you can ignore this email.</p>
                        ");
                });
            }
        }

        return response()->json([
            'message' => 'If that email is registered, a password reset link has been sent.',
        ]);
    }

    /**
     * POST /api/v1/auth/password/reset
     * Completes a reset started via forgot() — no active session required,
     * proven only by possessing the emailed token.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $reset = PasswordReset::where('token', $data['token'])
            ->where('used', false)
            ->with('user')
            ->first();

        if (! $reset) {
            return response()->json([
                'message' => 'Invalid or expired reset link.',
                'error'   => 'invalid_token',
            ], 422);
        }

        if ($reset->isExpired()) {
            return response()->json([
                'message' => 'This reset link has expired. Please request a new one.',
                'error'   => 'token_expired',
            ], 422);
        }

        $reset->user->update(['password' => $data['password']]);
        $reset->update(['used' => true]);

        // Any other outstanding reset tokens for this user are now moot.
        PasswordReset::where('user_id', $reset->user_id)
            ->where('id', '!=', $reset->id)
            ->where('used', false)
            ->delete();

        return response()->json(['message' => 'Password reset successfully. You can now sign in.']);
    }

    /**
     * POST /api/v1/auth/password/set
     * Authenticated — covers two cases in one endpoint:
     *  - Google-only account (no password yet): just set one, no current
     *    password to verify.
     *  - Account that already has a password: this becomes a change, so the
     *    current password must be confirmed first.
     */
    public function setPassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
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

        $hadPassword = $user->hasPassword();
        $user->update(['password' => $data['new_password']]);

        return response()->json([
            'message' => $hadPassword ? 'Password updated successfully.' : 'Password set successfully.',
        ]);
    }
}
