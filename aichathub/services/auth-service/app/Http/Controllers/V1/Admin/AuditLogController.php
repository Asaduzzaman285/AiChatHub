<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /** GET /admin/audit-logs — filters on columns that actually exist; there is no status column. */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('adminUser.user:id,name,email');

        $query
            ->when($request->filled('admin_user_id'), fn (Builder $q) => $q->where('admin_user_id', $request->query('admin_user_id')))
            ->when($request->filled('resource_type'), fn (Builder $q) => $q->where('resource_type', $request->query('resource_type')))
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', $request->query('action')))
            ->when($request->filled('ip_address'), fn (Builder $q) => $q->where('ip_address', $request->query('ip_address')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $logs    = $query->paginate($perPage);

        return response()->json([
            'audit_logs' => $logs->items(),
            'meta'       => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
