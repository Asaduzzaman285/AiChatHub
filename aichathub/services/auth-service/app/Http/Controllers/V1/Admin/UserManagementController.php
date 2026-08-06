<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-facing user listing + suspend/activate. Filters are limited to
 * columns that actually exist on `users` (name, email, phone, status,
 * email_verified_at, last_login_at, created_at) — no country column exists,
 * so no country filter.
 */
class UserManagementController extends Controller
{
    /** GET /admin/users */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        $query
            // Single free-text box (admin topbar search) — ORs across name/email, unlike the
            // name/email params below which stay independently ANDed for the Users page's own
            // filter form.
            ->when($request->filled('search'), fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->where('name', 'ILIKE', '%'.$request->query('search').'%')
                    ->orWhere('email', 'ILIKE', '%'.$request->query('search').'%')
            ))
            ->when($request->filled('name'), fn (Builder $q) => $q->where('name', 'ILIKE', '%'.$request->query('name').'%'))
            ->when($request->filled('email'), fn (Builder $q) => $q->where('email', 'ILIKE', '%'.$request->query('email').'%'))
            ->when($request->filled('phone'), fn (Builder $q) => $q->where('phone', 'ILIKE', '%'.$request->query('phone').'%'))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('email_verified'), function (Builder $q) use ($request) {
                $request->query('email_verified') === 'true'
                    ? $q->whereNotNull('email_verified_at')
                    : $q->whereNull('email_verified_at');
            })
            ->when($request->filled('last_login_from'), fn (Builder $q) => $q->where('last_login_at', '>=', $request->query('last_login_from')))
            ->when($request->filled('last_login_to'), fn (Builder $q) => $q->where('last_login_at', '<=', $request->query('last_login_to')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $users   = $query->paginate($perPage);

        return response()->json([
            'users' => collect($users->items())->map(fn (User $u) => [
                'id'                => $u->id,
                'name'              => $u->name,
                'email'             => $u->email,
                'phone'             => $u->phone,
                'status'            => $u->status,
                'email_verified_at' => $u->email_verified_at,
                'last_login_at'     => $u->last_login_at,
                'created_at'        => $u->created_at,
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'total'        => $users->total(),
            ],
        ]);
    }

    /** POST /admin/users/{id}/suspend */
    public function suspend(Request $request, string $id): JsonResponse
    {
        return $this->setStatus($request, $id, 'suspended');
    }

    /** POST /admin/users/{id}/unsuspend */
    public function unsuspend(Request $request, string $id): JsonResponse
    {
        return $this->setStatus($request, $id, 'active');
    }

    private function setStatus(Request $request, string $id, string $status): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['message' => 'User not found.', 'error' => 'not_found'], 404);
        }

        $oldStatus = $user->status;
        $user->update(['status' => $status]);

        AuditLog::create([
            'admin_user_id' => $this->currentAdmin($request)?->id,
            'action'        => $status === 'suspended' ? 'user.suspended' : 'user.unsuspended',
            'resource_type' => 'user',
            'resource_id'   => $user->id,
            'old_values'    => ['status' => $oldStatus],
            'new_values'    => ['status' => $status],
            'ip_address'    => $request->ip(),
            'user_agent'    => $request->userAgent(),
        ]);

        return response()->json(['message' => "User {$status}.", 'status' => $status]);
    }
}
