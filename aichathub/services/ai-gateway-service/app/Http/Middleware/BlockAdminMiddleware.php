<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inverse of AdminGateMiddleware — blocks admin JWTs from routes that assume a
 * consumer wallet exists (chat/generate/compare/transcribe, all behind
 * CostTrackingMiddleware). Admin accounts created via the 'create' mode of
 * auth-service's AdminUserController have no wallet at all, so without this an
 * admin hitting these routes would get wallet-service's Wallet::firstOrFail()
 * throwing, surfacing as a misleading 503 instead of an honest 403. Must run
 * after auth.jwt (needs auth_is_admin, set there from the X-Is-Admin header).
 */
class BlockAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('auth_is_admin')) {
            return response()->json([
                'message' => 'Admin accounts do not have wallet access — this endpoint is for consumer accounts only.',
                'error'   => 'admin_not_allowed',
            ], 403);
        }

        return $next($request);
    }
}
