<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manages admin_users itself — the "manage users and administrative roles"
 * Platform Administrator responsibility. Gated to admins.manage, which only
 * platform_admin's '*' wildcard grants by default. Permissions live entirely
 * on the assigned role now (see RoleController, AdminUser::permissions()) —
 * this controller only ever sets which role an admin holds, never a
 * permissions array directly.
 */
class AdminUserController extends Controller
{
    /** GET /admin/admins */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        // Eager-load roleRecord too — the permissions accessor reads it, and
        // without this it'd fire one query per row (N+1) to resolve it.
        $admins  = AdminUser::with(['user:id,name,email', 'roleRecord'])->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'admins' => $admins->items(),
            'meta'   => [
                'current_page' => $admins->currentPage(),
                'last_page'    => $admins->lastPage(),
                'total'        => $admins->total(),
            ],
        ]);
    }

    /** POST /admin/admins — promotes an existing user to admin, assigned to an existing role. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'role'    => ['required', 'string', Rule::exists('roles', 'name')],
        ]);

        if (AdminUser::where('user_id', $data['user_id'])->exists()) {
            return response()->json(['message' => 'This user is already an admin.', 'error' => 'already_admin'], 409);
        }

        $admin = AdminUser::create([
            'user_id'   => $data['user_id'],
            'role'      => $data['role'],
            'is_active' => true,
        ]);

        $this->logAction($request, 'admin.created', 'admin_user', $admin->id, null, $admin->fresh()->toArray());

        return response()->json(['admin' => $admin->fresh('user')], 201);
    }

    /** PATCH /admin/admins/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $admin = AdminUser::find($id);

        if (! $admin) {
            return response()->json(['message' => 'Admin not found.', 'error' => 'not_found'], 404);
        }

        $data = $request->validate([
            'role'      => ['sometimes', 'string', Rule::exists('roles', 'name')],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $old = $admin->fresh()->toArray();
        $admin->update($data);

        $this->logAction($request, 'admin.updated', 'admin_user', $admin->id, $old, $admin->fresh()->toArray());

        return response()->json(['admin' => $admin->fresh('user')]);
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
