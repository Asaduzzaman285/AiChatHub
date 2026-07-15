<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal-only controller.
 * Called by other microservices via X-Internal-Service-Key header.
 * Never exposed to the public internet.
 */
class UserController extends Controller
{
    /**
     * GET /api/internal/users/{userId}
     * Fetch a user by UUID — used by wallet-service, subscription-service, etc.
     */
    public function show(string $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'id'                 => $user->id,
            'email'              => $user->email,
            'name'               => $user->name,
            'status'             => $user->status,
            'preferred_currency' => $user->preferred_currency,
            'email_verified_at'  => $user->email_verified_at,
            'created_at'         => $user->created_at,
        ]);
    }

    /**
     * GET /api/internal/users/email/{email}
     * Look up a user by email — used during cross-service user resolution.
     */
    public function findByEmail(string $email): JsonResponse
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        return response()->json([
            'id'                 => $user->id,
            'email'              => $user->email,
            'name'               => $user->name,
            'status'             => $user->status,
            'preferred_currency' => $user->preferred_currency,
            'email_verified_at'  => $user->email_verified_at,
        ]);
    }

    /**
     * POST /api/internal/users/{userId}/suspend
     * Suspend a user account — called by admin or billing service.
     */
    public function suspend(string $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->update(['status' => 'suspended']);

        return response()->json(['message' => 'User suspended.', 'status' => 'suspended']);
    }

    /**
     * POST /api/internal/users/{userId}/unsuspend
     * Re-activate a suspended user account.
     */
    public function unsuspend(string $userId): JsonResponse
    {
        $user = User::find($userId);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $user->update(['status' => 'active']);

        return response()->json(['message' => 'User unsuspended.', 'status' => 'active']);
    }
}
