<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use Illuminate\Http\Request;

abstract class Controller
{
    /** Whether the caller is a real, active admin — set by AdminGateMiddleware. */
    protected function isAdmin(Request $request): bool
    {
        return (bool) $request->attributes->get('auth_admin');
    }

    /** The caller's admin_users row (role, permissions) — set by AdminGateMiddleware. */
    protected function currentAdmin(Request $request): ?AdminUser
    {
        return $request->attributes->get('auth_admin');
    }

    protected function hasPermission(Request $request, string $permission): bool
    {
        $permissions = $this->currentAdmin($request)?->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
