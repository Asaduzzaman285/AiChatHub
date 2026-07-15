<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;

class LogoutController extends Controller
{
    public function __construct(private JwtService $jwtService) {}

    /**
     * POST /api/v1/auth/logout
     * Revoke current access token + all refresh tokens for user.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Invalidate the current JWT
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
        } catch (\Exception) {
            // Token already invalid — that's fine
        }

        // Revoke all refresh tokens
        $this->jwtService->revokeAll($user);

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
