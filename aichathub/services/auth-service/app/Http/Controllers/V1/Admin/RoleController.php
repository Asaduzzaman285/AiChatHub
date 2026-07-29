<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manages roles themselves — real, admin-editable objects (replacing the old
 * config/admin_roles.php fixed list). Editing a role's permissions here
 * propagates live to every admin who holds it (AdminUser::permissions is a
 * computed accessor, not a frozen column) — the next time any of those
 * admins' tokens are issued they carry the new set.
 */
class RoleController extends Controller
{
    /** GET /admin/roles */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('admins')->orderBy('name')->get()->map(fn (Role $role) => [
            'id'          => $role->id,
            'name'        => $role->name,
            'permissions' => $role->permissions,
            'admin_count' => $role->admins_count,
            'created_at'  => $role->created_at,
        ]);

        return response()->json(['roles' => $roles]);
    }

    /** POST /admin/roles */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100|unique:roles,name',
            'permissions'     => 'required|array|min:1',
            'permissions.*'   => 'string',
        ]);

        $role = Role::create($data);

        $this->logAction($request, 'role.created', 'role', $role->id, null, $role->toArray());

        return response()->json(['role' => $role], 201);
    }

    /** PATCH /admin/roles/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json(['message' => 'Role not found.', 'error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'name'          => ['sometimes', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions'   => 'sometimes|array|min:1',
            'permissions.*' => 'string',
        ]);

        // admin_users.role is a plain string FK-by-name, checked immediately
        // (not deferred) — cascading a rename across every admin_users row in
        // the same transaction is order-dependent and can violate the
        // constraint either way it's sequenced. Simpler and safer: block
        // renaming a role while it's assigned, same as delete already does.
        // Editing permissions (the actual live-link feature) never touches
        // `name`, so this doesn't limit the feature that matters.
        if (isset($data['name']) && $data['name'] !== $role->name && AdminUser::where('role', $role->name)->exists()) {
            return response()->json([
                'message' => 'This role is assigned to one or more admins and cannot be renamed. Reassign them first.',
                'error'   => 'role_in_use',
            ], 409);
        }

        $old = $role->toArray();
        $role->update($data);

        $this->logAction($request, 'role.updated', 'role', $role->id, $old, $role->fresh()->toArray());

        return response()->json(['role' => $role->fresh()]);
    }

    /** DELETE /admin/roles/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $role = Role::find($id);

        if (! $role) {
            return response()->json(['message' => 'Role not found.', 'error' => 'not_found'], 404);
        }

        if (AdminUser::where('role', $role->name)->exists()) {
            return response()->json([
                'message' => 'This role is still assigned to one or more admins. Reassign them first.',
                'error'   => 'role_in_use',
            ], 409);
        }

        $old = $role->toArray();
        $role->delete();

        $this->logAction($request, 'role.deleted', 'role', $id, $old, null);

        return response()->json(['message' => 'Role deleted.']);
    }

    private function logAction(Request $request, string $action, string $resourceType, string $resourceId, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'admin_user_id' => $this->currentAdmin($request)?->id,
            'action'        => $action,
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
            'old_values'    => $old,
            'new_values'    => $new,
            'ip_address'    => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);
    }
}
