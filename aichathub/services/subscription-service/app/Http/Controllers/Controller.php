<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** User id forwarded by the API Gateway via the X-User-Id header (see JwtAuthMiddleware). */
    protected function authUserId(Request $request): string
    {
        return $request->attributes->get('auth_user_id');
    }

    /** Whether the caller is a real admin (admin_users, checked at JWT issuance) — set by JwtAuthMiddleware. */
    protected function isAdmin(Request $request): bool
    {
        return (bool) $request->attributes->get('auth_is_admin');
    }

    protected function hasPermission(Request $request, string $permission): bool
    {
        $permissions = $request->attributes->get('auth_admin_permissions', []);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    /** admin_users.id of the calling admin, forwarded via X-Admin-Id — for audit logging. */
    protected function adminId(Request $request): ?string
    {
        return $request->attributes->get('auth_admin_id');
    }
}
