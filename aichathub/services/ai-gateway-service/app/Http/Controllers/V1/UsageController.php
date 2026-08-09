<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer-scoped counterpart to Admin\UsageLogAdminController — same usage_logs table,
 * hard-scoped to the authenticated user (no user_id query param, that would leak other
 * users' logs) so a customer can see their own per-model token/cost breakdown.
 */
class UsageController extends Controller
{
    /** GET /models/usage/summary?period=7d|30d|all — per-model totals for the current user. */
    public function summary(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $period = $request->query('period', '30d');
        $days   = match ($period) {
            '7d'    => 7,
            'all'   => null,
            default => 30,
        };

        // Table-qualified — $models below joins ai_models, which also has its own
        // created_at (from $table->timestamps()); an unqualified where() here is
        // ambiguous the moment the join is applied (caught live: SQLSTATE[42702]).
        $base = UsageLog::query()->where('usage_logs.user_id', $userId);
        if ($days !== null) {
            $base->where('usage_logs.created_at', '>=', now()->subDays($days));
        }

        $models = (clone $base)
            ->join('ai_models', 'ai_models.id', '=', 'usage_logs.model_id')
            ->groupBy('ai_models.id', 'ai_models.name', 'ai_models.provider')
            ->selectRaw('ai_models.id as model_id, ai_models.name, ai_models.provider')
            ->selectRaw('SUM(usage_logs.prompt_tokens) as prompt_tokens')
            ->selectRaw('SUM(usage_logs.completion_tokens) as completion_tokens')
            ->selectRaw('SUM(usage_logs.total_tokens) as total_tokens')
            ->selectRaw('SUM(usage_logs.actual_cost) as cost')
            ->orderByDesc('total_tokens')
            ->get();

        $totals = (clone $base)
            ->selectRaw('COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->selectRaw('COALESCE(SUM(actual_cost), 0) as cost')
            ->first();

        return response()->json([
            'period' => $period,
            'models' => $models,
            'totals' => $totals,
        ]);
    }

    /** GET /models/usage?per_page= — paginated recent-activity list for the current user. */
    public function index(Request $request): JsonResponse
    {
        $query = UsageLog::with('model:id,provider,name,model_id')
            ->where('user_id', $this->authUserId($request))
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
