<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\SubscriptionHistory;
use App\Models\UserSubscription;
use App\Services\PackageActivationService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private PackageActivationService $activation,
    ) {}

    /** GET /subscription — current active subscription for the authenticated user */
    public function current(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->getActive($this->authUserId($request));

        return response()->json([
            'subscription' => $subscription ? $this->formatSubscription($subscription) : null,
        ]);
    }

    /**
     * POST /subscription/subscribe
     * Wallet path charges synchronously and activates immediately. Card path
     * hands back a Stripe Checkout URL instead — activation is deferred until
     * the payment is verified (SubscriptionActivationController::activate(),
     * called by Payment Service once the Checkout Session completes).
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_slug'   => 'required|string|exists:packages,slug',
            'payment_source' => 'required|in:wallet,card',
            'currency'       => 'nullable|string|size:3',
        ]);

        $userId = $this->authUserId($request);

        if ($this->subscriptions->getActive($userId)) {
            return response()->json([
                'message' => 'You already have an active subscription. Use upgrade/downgrade instead.',
                'error'   => 'already_subscribed',
            ], 409);
        }

        $package = Package::where('slug', $data['package_slug'])->where('is_active', true)->firstOrFail();

        $currency = $data['currency'] ?? 'USD';
        $price    = (float) $package->monthly_price_usd;

        if ($price > 0 && $data['payment_source'] === 'card') {
            $checkoutUrl = $this->createCardCheckout($userId, $price, $currency, $package);

            if (! $checkoutUrl) {
                return response()->json(['message' => 'Could not start card checkout. Please try again.', 'error' => 'checkout_failed'], 502);
            }

            return response()->json(['checkout_url' => $checkoutUrl]);
        }

        $transactionId = (string) Str::uuid();

        if ($price > 0) {
            $charged = $this->chargeWallet($userId, $price, $transactionId, 'Subscription: '.$package->name);

            if (! $charged) {
                return response()->json(['message' => 'Insufficient wallet balance for this package.', 'error' => 'insufficient_wallet_balance'], 402);
            }
        }

        $subscription = $this->activation->activate($userId, $package, $transactionId, $currency);

        return response()->json([
            'message'      => 'Subscribed successfully.',
            'subscription' => $this->formatSubscription($subscription->fresh('package')),
        ], 201);
    }

    /** POST /subscription/upgrade */
    public function upgrade(Request $request): JsonResponse
    {
        return $this->changePackage($request, 'upgrade');
    }

    /** POST /subscription/downgrade */
    public function downgrade(Request $request): JsonResponse
    {
        return $this->changePackage($request, 'downgrade');
    }

    /** POST /subscription/cancel */
    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $subscription = $this->subscriptions->getActive($this->authUserId($request));

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription found.', 'error' => 'no_active_subscription'], 404);
        }

        $this->subscriptions->cancelAtEndOfCycle($subscription, $data['reason'] ?? '');

        return response()->json([
            'message'      => 'Subscription will be cancelled at the end of the current billing cycle.',
            'access_until' => $subscription->renews_at,
        ]);
    }

    /** GET /subscription/history */
    public function history(Request $request): JsonResponse
    {
        $entries = SubscriptionHistory::where('user_id', $this->authUserId($request))
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'history' => $entries->items(),
            'meta'    => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }

    private function changePackage(Request $request, string $direction): JsonResponse
    {
        $data = $request->validate([
            'package_slug' => 'required|string|exists:packages,slug',
        ]);

        $userId  = $this->authUserId($request);
        $current = $this->subscriptions->getActive($userId);

        if (! $current) {
            return response()->json(['message' => 'No active subscription found.', 'error' => 'no_active_subscription'], 404);
        }

        $newPackage = Package::where('slug', $data['package_slug'])->where('is_active', true)->firstOrFail();

        if ($newPackage->id === $current->package_id) {
            return response()->json(['message' => 'Already subscribed to this package.', 'error' => 'same_package'], 409);
        }

        $isHigherTier = (float) $newPackage->monthly_price_usd > (float) $current->package->monthly_price_usd;

        if ($direction === 'upgrade' && ! $isHigherTier) {
            return response()->json(['message' => 'Target package is not an upgrade.', 'error' => 'not_an_upgrade'], 422);
        }
        if ($direction === 'downgrade' && $isHigherTier) {
            return response()->json(['message' => 'Target package is not a downgrade.', 'error' => 'not_a_downgrade'], 422);
        }

        $transactionId   = (string) Str::uuid();
        $oldWalletCredit = (float) $current->package->monthly_wallet_credit_usd;

        $subscription = $direction === 'upgrade'
            ? $this->subscriptions->upgrade($current, $newPackage, $transactionId)
            : $this->subscriptions->downgrade($current, $newPackage, $transactionId);

        // Phase 1 simplification: no proration — an upgrade credits the wallet
        // for the difference in monthly allowance; a downgrade credits nothing extra.
        $creditDiff     = (float) $newPackage->monthly_wallet_credit_usd - $oldWalletCredit;
        $walletCredited = $creditDiff > 0
            ? $this->activation->creditWallet($userId, $creditDiff, $subscription->id, ucfirst($direction).' credit: '.$newPackage->name)
            : false;

        return response()->json([
            'message'         => ucfirst($direction).'d successfully.',
            'subscription'    => $this->formatSubscription($subscription->fresh('package')),
            'wallet_credited' => $walletCredited,
        ]);
    }

    /** Reserve+deduct against the wallet — same two-step Wallet Service uses for AI cost, reused here for a synchronous purchase charge. */
    private function chargeWallet(string $userId, float $amount, string $transactionId, string $description): bool
    {
        $walletUrl   = rtrim(config('services.wallet_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $walletUrl || ! $internalKey) {
            Log::error('Wallet charge skipped — wallet_url/internal_key not configured.', ['user_id' => $userId]);
            return false;
        }

        try {
            $reserve = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->post("{$walletUrl}/api/internal/wallet/reserve", [
                'user_id' => $userId,
                'amount'  => $amount,
            ]);

            if (! $reserve->successful()) {
                return false;
            }

            $deduct = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->post("{$walletUrl}/api/internal/wallet/deduct", [
                'user_id'         => $userId,
                'amount'          => $amount,
                'reserved_amount' => $amount,
                'description'     => $description,
                'reference_type'  => 'subscription_purchase',
                'reference_id'    => $transactionId,
            ]);

            return $deduct->successful();
        } catch (\Exception $e) {
            Log::error('Wallet charge failed: '.$e->getMessage(), ['user_id' => $userId]);
            return false;
        }
    }

    /** Asks Payment Service to open a Stripe Checkout Session for this package purchase. */
    private function createCardCheckout(string $userId, float $amount, string $currency, Package $package): ?string
    {
        $paymentUrl  = rtrim(config('services.payment_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $paymentUrl || ! $internalKey) {
            Log::error('Card checkout skipped — payment_url/internal_key not configured.', ['user_id' => $userId]);
            return null;
        }

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(20)->post("{$paymentUrl}/api/internal/payments/checkout", [
                'user_id'      => $userId,
                'amount'       => $amount,
                'currency'     => $currency,
                'description'  => 'Subscription: '.$package->name,
                'package_slug' => $package->slug,
            ]);

            return $response->successful() ? $response->json('checkout_url') : null;
        } catch (\Exception $e) {
            Log::error('Card checkout creation failed: '.$e->getMessage(), ['user_id' => $userId]);
            return null;
        }
    }

    private function formatSubscription(UserSubscription $subscription): array
    {
        return [
            'id'           => $subscription->id,
            'status'       => $subscription->status,
            'auto_renew'   => $subscription->auto_renew,
            'currency'     => $subscription->currency,
            'activated_at' => $subscription->activated_at,
            'renews_at'    => $subscription->renews_at,
            'cancelled_at' => $subscription->cancelled_at,
            'package'      => $subscription->package ? [
                'id'                => $subscription->package->id,
                'name'              => $subscription->package->name,
                'slug'              => $subscription->package->slug,
                'monthly_price_usd' => (float) $subscription->package->monthly_price_usd,
                'model_access'      => $subscription->package->model_access,
                'features'          => $subscription->package->features,
            ] : null,
        ];
    }
}
