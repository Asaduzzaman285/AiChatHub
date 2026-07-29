<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates admin-only routes. Must run after auth.jwt (needs auth_is_admin /
 * auth_admin_permissions, set there from the X-Is-Admin / X-Admin-Permissions
 * headers api-gateway's JwtGatewayMiddleware forwards — sourced from the
 * is_admin/admin_permissions JWT claims, computed at login from admin_users).
 *
 * Optional $permission param: no param = any active admin; with one, 403s
 * unless the admin has '*' or that exact string in their permissions.
 */
class AdminGateMiddleware
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        if (! $request->attributes->get('auth_is_admin')) {
            return response()->json(['message' => 'Admin access required.', 'error' => 'forbidden'], 403);
        }

        if ($permission !== null) {
            $permissions = $request->attributes->get('auth_admin_permissions', []);
            if (! in_array('*', $permissions, true) && ! in_array($permission, $permissions, true)) {
                return response()->json(['message' => 'Admin access required.', 'error' => 'forbidden'], 403);
            }
        }

        return $next($request);
    }
}
