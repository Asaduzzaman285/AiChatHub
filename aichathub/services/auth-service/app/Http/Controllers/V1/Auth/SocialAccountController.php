<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialAccountController extends Controller
{
    /**
     * GET /api/v1/auth/social
     * List connected social accounts for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()
            ->socialAccounts()
            ->get(['provider', 'created_at'])
            ->map(fn($a) => [
                'provider'   => $a->provider,
                'connected'  => true,
                'connected_at' => $a->created_at,
            ]);

        return response()->json(['accounts' => $accounts]);
    }

    /**
     * POST /api/v1/auth/social/google/link
     * Link a Google account using a Firebase ID token.
     * Delegates to FirebaseAuthController logic — just an alias route.
     */
    public function linkGoogle(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Use POST /api/v1/auth/firebase to link a Google account.',
        ], 400);
    }

    /**
     * DELETE /api/v1/auth/social/google
     * Unlink Google from the user's account.
     * Only allowed if the user has a password set.
     */
    public function unlinkGoogle(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasPassword()) {
            return response()->json([
                'message' => 'Cannot unlink Google — this account has no password. Set a password first.',
                'error'   => 'no_password_set',
            ], 422);
        }

        $deleted = $user->socialAccounts()
            ->where('provider', 'google')
            ->delete();

        if (! $deleted) {
            return response()->json([
                'message' => 'No Google account linked.',
                'error'   => 'not_linked',
            ], 404);
        }

        return response()->json(['message' => 'Google account unlinked successfully.']);
    }
}
