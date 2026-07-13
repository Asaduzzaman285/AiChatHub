<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;

class SubscriptionCheckController extends Controller
{
    /**
     * GET /internal/subscriptions/{userId}/current
     * Used by AI Gateway to check what package a user has.
     */
    public function current(string $userId): JsonResponse
    {
        $subscription = UserSubscription::with('package')
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'past_due'])
            ->first();

        if (! $subscription) {
            return response()->json(['subscription' => null], 200);
        }

        return response()->json([
            'subscription_id' => $subscription->id,
            'status'          => $subscription->status,
            'renews_at'       => $subscription->renews_at,
            'package'         => [
                'id'           => $subscription->package->id,
                'name'         => $subscription->package->name,
                'slug'         => $subscription->package->slug,
                'model_access' => $subscription->package->model_access,
                'features'     => $subscription->package->features,
            ],
        ]);
    }

    /**
     * GET /internal/subscriptions/{userId}/can-access/{modelId}
     * Fast boolean check for AI Gateway pre-flight.
     */
    public function canAccess(string $userId, string $modelId): JsonResponse
    {
        $subscription = UserSubscription::with('package')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if (! $subscription) {
            return response()->json(['allowed' => false, 'reason' => 'no_active_subscription']);
        }

        $allowed = $subscription->package->allowsModel($modelId);

        return response()->json([
            'allowed'  => $allowed,
            'reason'   => $allowed ? null : 'model_not_in_package',
            'package'  => $subscription->package->slug,
        ]);
    }
}
