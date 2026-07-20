<?php

namespace App\Http\Controllers\V1\Auth;

use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Models\EmailVerification;
use App\Models\User;
use App\Services\NotificationClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private NotificationClient $notificationClient) {}

    /**
     * GET /api/v1/auth/verify/{token}
     * Verify email address using the token sent by email.
     */
    public function verify(string $token): JsonResponse
    {
        $verification = EmailVerification::where('token', $token)
            ->where('used', false)
            ->with('user')
            ->first();

        if (! $verification) {
            return response()->json([
                'message' => 'Invalid or expired verification link.',
                'error'   => 'invalid_token',
            ], 422);
        }

        if ($verification->isExpired()) {
            return response()->json([
                'message' => 'Verification link has expired. Please request a new one.',
                'error'   => 'token_expired',
            ], 422);
        }

        // Activate the user
        $verification->update(['used' => true]);
        $verification->user->update([
            'email_verified_at' => now(),
            'status'            => 'active',
        ]);

        // Non-blocking — the user shouldn't wait on an SMTP round-trip just to see
        // "verified successfully", same reasoning as invoice creation elsewhere.
        $userId = $verification->user->id;
        $email  = $verification->user->email;
        $name   = $verification->user->name;
        dispatch(function () use ($userId, $email, $name) {
            $this->notificationClient->send('welcome', $userId, $email, ['name' => $name], "welcome:{$userId}");
        })->afterResponse();

        return response()->json([
            'message' => 'Email verified successfully. You can now sign in.',
        ]);
    }

    /**
     * POST /api/v1/auth/verify/resend
     * Resend verification email to user.
     */
    public function resend(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->email)
            ->where('status', 'pending_verification')
            ->first();

        if (! $user) {
            // Don't reveal if email exists — return success either way
            return response()->json([
                'message' => 'If that email exists and is unverified, a new link has been sent.',
            ]);
        }

        // Throttle: max one resend per 2 minutes
        $recentVerification = \App\Models\EmailVerification::where('user_id', $user->id)
            ->where('used', false)
            ->where('created_at', '>=', now()->subMinutes(2))
            ->exists();

        if ($recentVerification) {
            return response()->json([
                'message' => 'A verification email was recently sent. Please wait 2 minutes before requesting another.',
            ], 429);
        }

        event(new UserRegistered($user));

        return response()->json([
            'message' => 'If that email exists and is unverified, a new link has been sent.',
        ]);
    }
}
