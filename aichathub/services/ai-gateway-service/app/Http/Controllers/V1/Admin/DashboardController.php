<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CircuitBreakerState;
use App\Models\UsageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /admin/dashboard — ai-gateway-service's own contribution to the admin dashboard. */
    public function index(Request $request): JsonResponse
    {
        $since = now()->subDays(7);

        $providerBreakdown = UsageLog::query()
            ->where('created_at', '>=', $since)
            ->with('model:id,provider')
            ->get()
            ->groupBy(fn ($u) => $u->model->provider ?? 'unknown')
            ->map(fn ($rows) => [
                'requests' => $rows->count(),
                'tokens'   => (int) $rows->sum('total_tokens'),
                'cost'     => (float) $rows->sum('actual_cost'),
            ]);

        // Real provider health, not synthetic — closed/open/half_open per model.
        $providerHealth = CircuitBreakerState::with('model:id,provider,name')->get()->map(fn ($c) => [
            'model'    => $c->model->name ?? null,
            'provider' => $c->model->provider ?? null,
            'state'    => $c->state,
        ]);

        return response()->json([
            'total_tokens_7d'    => (int) UsageLog::where('created_at', '>=', $since)->sum('total_tokens'),
            'total_cost_7d'      => (float) UsageLog::where('created_at', '>=', $since)->sum('actual_cost'),
            'failed_requests_7d' => UsageLog::where('created_at', '>=', $since)->where('status', 'failed')->count(),
            'provider_breakdown' => $providerBreakdown,
            'provider_health'    => $providerHealth,
        ]);
    }
}
