<?php

namespace App\Http\Controllers\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenRefreshController extends Controller
{
    public function __construct(private JwtService $jwtService) {}

    /**
     * POST /api/v1/auth/refresh
     * Exchange a valid refresh token for a new JWT pair.
     */
    public function refresh(Request $request): JsonResponse
    {
        $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        try {
            $tokens = $this->jwtService->rotateRefreshToken($request->refresh_token);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'message' => 'Invalid or expired refresh token.',
                'error'   => 'refresh_token_invalid',
            ], 401);
        }

        return response()->json($tokens);
    }
}
