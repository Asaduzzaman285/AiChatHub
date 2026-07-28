<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates admin-only routes. Must run after auth.jwt (needs auth_is_admin, set
 * there from the X-Is-Admin header api-gateway's JwtGatewayMiddleware forwards
 * — itself sourced from the is_admin JWT claim, computed at login from the
 * admin_users table).
 */
class AdminGateMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->attributes->get('auth_is_admin')) {
            return response()->json(['message' => 'Admin access required.', 'error' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
