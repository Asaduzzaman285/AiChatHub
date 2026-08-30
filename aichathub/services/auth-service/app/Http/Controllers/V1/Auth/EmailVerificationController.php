<?php

namespace App\Http\Controllers\V1\Auth;

use App\Events\UserRegistered;
use App\Http\Controllers\Controller;
use App\Models\EmailVerification;
use App\Models\User;
use App\Services\NotificationClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private NotificationClient $notificationClient) {}

    /**
     * GET /api/v1/auth/verify/{token}
     * Verify email address using the token sent by email.
     *
     * This link is clicked directly from a real email — the whole point is landing
     * the user back in the app, not showing them a bare JSON response from the API
     * domain (confirmed live: that's exactly what was happening before this fix,
     * since this is a plain API endpoint with no view of its own). Redirects into
     * frontend_url for the original-registration success/failure paths below; the
     * email-CHANGE confirmation branch further down is a different flow (the user is
     * typically already an active, logged-in session mid-account-settings, not
     * arriving fresh) and is left as JSON, unchanged, since redirecting it to /login
     * wouldn't make sense.
     */
    public function verify(string $token): RedirectResponse|JsonResponse
    {
        $frontendUrl = rtrim(config('services.frontend_url'), '/');

        $verification = EmailVerification::where('token', $token)
            ->where('used', false)
            ->with('user')
            ->first();

        if (! $verification) {
            return redirect("{$frontendUrl}/login?verified=0&reason=invalid_token");
        }

        if ($verification->isExpired()) {
            return redirect("{$frontendUrl}/login?verified=0&reason=token_expired");
        }

        // An email-change confirmation (see EmailChangeController) — distinct from the
        // original-registration case below: the account is already active/verified,
        // just switching addresses. Re-check uniqueness here too, not just at request
        // time, in case someone else claimed this address in the meantime.
        if ($verification->new_email !== null) {
            if (User::where('email', $verification->new_email)->exists()) {
                return response()->json([
                    'message' => 'That email is no longer available. Please request the change again with a different address.',
                    'error'   => 'email_taken',
                ], 422);
            }

            $verification->update(['used' => true]);
            $verification->user->update(['email' => $verification->new_email]);

            return response()->json([
                'message' => 'Email address updated successfully.',
            ]);
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

        return redirect("{$frontendUrl}/login?verified=1");
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
