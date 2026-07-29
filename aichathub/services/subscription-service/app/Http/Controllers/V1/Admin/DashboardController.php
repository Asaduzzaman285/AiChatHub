<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** GET /admin/dashboard — subscription-service's own contribution to the admin dashboard. */
    public function index(Request $request): JsonResponse
    {
        $planBreakdown = UserSubscription::query()
            ->whereIn('status', ['active', 'past_due'])
            ->with('package:id,name')
            ->get()
            ->groupBy(fn ($s) => $s->package->name ?? 'unknown')
            ->map->count();

        return response()->json([
            'active_subscriptions'    => UserSubscription::whereIn('status', ['active', 'past_due'])->count(),
            'past_due_subscriptions'  => UserSubscription::where('status', 'past_due')->count(),
            'scheduled_downgrades'    => UserSubscription::whereNotNull('scheduled_package_id')->count(),
            'plan_breakdown'          => $planBreakdown,
        ]);
    }
}
