<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Services\PackageActivationService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SubscriptionActivationController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private PackageActivationService $activation,
    ) {}

    /**
     * POST /internal/subscriptions/activate
     * Called by Payment Service's CheckoutCompletionService once a card-funded
     * package purchase's Checkout Session is verified paid. By this point money
     * has already moved, so unlike SubscriptionController::subscribe()'s upfront
     * 409, an "already subscribed" finding here is a defensive skip-and-log, not
     * a user-facing error — it means another path (e.g. the user separately
     * subscribed via wallet balance) won a race, not that this request failed.
     */
    public function activate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'        => 'required|uuid',
            'package_slug'   => 'required|string|exists:packages,slug',
            'transaction_id' => 'required|uuid',
            'currency'       => 'nullable|string|size:3',
        ]);

        if ($this->subscriptions->getActive($data['user_id'])) {
            Log::warning('Subscription activation skipped — user already has an active subscription.', [
                'user_id'        => $data['user_id'],
                'transaction_id' => $data['transaction_id'],
            ]);

            return response()->json(['message' => 'Already active, activation skipped.', 'skipped' => true]);
        }

        $package = Package::where('slug', $data['package_slug'])->where('is_active', true)->firstOrFail();

        $subscription = $this->activation->activate(
            $data['user_id'],
            $package,
            $data['transaction_id'],
            $data['currency'] ?? 'USD',
        );

        return response()->json(['subscription_id' => $subscription->id, 'skipped' => false], 201);
    }
}
