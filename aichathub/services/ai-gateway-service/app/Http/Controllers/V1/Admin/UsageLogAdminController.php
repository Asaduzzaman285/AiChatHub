<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageLogAdminController extends Controller
{
    /** GET /admin/usage-logs — filters on columns that actually exist on usage_logs. */
    public function index(Request $request): JsonResponse
    {
        $query = UsageLog::with('model:id,provider,name,model_id');

        $query
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', $request->query('user_id')))
            ->when($request->filled('provider'), function (Builder $q) use ($request) {
                $q->whereHas('model', fn (Builder $m) => $m->where('provider', $request->query('provider')));
            })
            ->when($request->filled('model_id'), fn (Builder $q) => $q->where('model_id', $request->query('model_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('min_tokens'), fn (Builder $q) => $q->where('total_tokens', '>=', $request->query('min_tokens')))
            ->when($request->filled('max_tokens'), fn (Builder $q) => $q->where('total_tokens', '<=', $request->query('max_tokens')))
            ->when($request->filled('min_duration_ms'), fn (Builder $q) => $q->where('duration_ms', '>=', $request->query('min_duration_ms')))
            ->when($request->filled('max_duration_ms'), fn (Builder $q) => $q->where('duration_ms', '<=', $request->query('max_duration_ms')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->query('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->query('to')))
            ->orderByDesc('created_at');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $logs    = $query->paginate($perPage);

        return response()->json([
            'usage_logs' => $logs->items(),
            'meta'       => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'total'        => $logs->total(),
            ],
        ]);
    }
}
