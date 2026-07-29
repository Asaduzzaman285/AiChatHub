<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Unlike every other service's AdminGateMiddleware (which trusts the
 * X-Is-Admin/X-Admin-Permissions headers api-gateway forwards from the JWT),
 * auth-service IS the source of truth for admin status — auth.jwt here
 * resolves the real $user model via Tymon, so this queries admin_users
 * directly instead of trusting a header.
 *
 * Optional $permission param: no param = any active admin (unchanged
 * behavior); with one, 403s unless the admin has '*' or that exact string in
 * admin_users.permissions.
 */
class AdminGateMiddleware
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user  = $request->user();
        $admin = $user ? AdminUser::where('user_id', $user->id)->where('is_active', true)->first() : null;

        if (! $admin) {
            return response()->json(['message' => 'Admin access required.', 'error' => 'forbidden'], 403);
        }

        $permissions = $admin->permissions ?? [];

        if ($permission !== null && ! in_array('*', $permissions, true) && ! in_array($permission, $permissions, true)) {
            return response()->json(['message' => 'Admin access required.', 'error' => 'forbidden'], 403);
        }

        $request->attributes->set('auth_admin', $admin);

        return $next($request);
    }
}
