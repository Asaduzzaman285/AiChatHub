<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionAdminController extends Controller
{
    /** GET /admin/subscriptions — filters on columns that actually exist; no billing-cycle/expiration filters (neither concept exists here). */
    public function index(Request $request): JsonResponse
    {
        $query = UserSubscription::with(['package', 'scheduledPackage']);

        $query
            ->when($request->filled('user_id'), fn (Builder $q) => $q->where('user_id', $request->query('user_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->query('status')))
            ->when($request->filled('auto_renew'), fn (Builder $q) => $q->where('auto_renew', $request->query('auto_renew') === 'true'))
            ->when($request->filled('package_slug'), function (Builder $q) use ($request) {
                $q->whereHas('package', fn (Builder $p) => $p->where('slug', $request->query('package_slug')));
            })
            ->when($request->filled('renews_from'), fn (Builder $q) => $q->where('renews_at', '>=', $request->query('renews_from')))
            ->when($request->filled('renews_to'), fn (Builder $q) => $q->where('renews_at', '<=', $request->query('renews_to')))
            ->orderByDesc('activated_at');

        $perPage       = min((int) $request->query('per_page', 20), 100);
        $subscriptions = $query->paginate($perPage);

        return response()->json([
            'subscriptions' => $subscriptions->items(),
            'meta'          => [
                'current_page' => $subscriptions->currentPage(),
                'last_page'    => $subscriptions->lastPage(),
                'total'        => $subscriptions->total(),
            ],
        ]);
    }
}
