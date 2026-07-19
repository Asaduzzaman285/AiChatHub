<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\SubscriptionHistory;
use App\Models\UserSubscription;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subscriptions) {}

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
     * Phase 1: Payment Service is not built yet, so this treats the request
     * as already-authorized rather than charging a real payment method
     * (mirrors the wallet-auto-create-on-register simplification in auth-service).
     * Wallet credit happens synchronously so the response balance is accurate;
     * invoice creation is fired afterResponse() since it isn't required for
     * the purchase itself to have succeeded.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_slug'         => 'required|string|exists:packages,slug',
            'payment_method_token' => 'nullable|string',
            'currency'             => 'nullable|string|size:3',
        ]);

        $userId = $this->authUserId($request);

        if ($this->subscriptions->getActive($userId)) {
            return response()->json([
                'message' => 'You already have an active subscription. Use upgrade/downgrade instead.',
                'error'   => 'already_subscribed',
            ], 409);
        }

        $package = Package::where('slug', $data['package_slug'])->where('is_active', true)->firstOrFail();

        $currency      = $data['currency'] ?? 'USD';
        $transactionId = (string) Str::uuid();

        // Phase 1: no Payment Service charge flow wired in yet, so there's no stored
        // PaymentMethod record to reference — payment_method_id stays null. The raw
        // payment_method_token from the request isn't persisted (it's a Stripe token,
        // not a UUID, and the column is typed uuid).
        $subscription = $this->subscriptions->subscribe(
            $userId,
            $package,
            $transactionId,
            $currency,
            1.000000,
            null,
        );

        $walletCredited = $this->creditWallet(
            $userId,
            (float) $package->monthly_wallet_credit_usd,
            $subscription->id,
            'Subscription credit: '.$package->name,
            activateCreditBuffer: true,
        );

        $this->createInvoiceAfterResponse($userId, $subscription->id, $package, $currency, $transactionId);

        return response()->json([
            'message'         => 'Subscribed successfully.',
            'subscription'    => $this->formatSubscription($subscription->fresh('package')),
            'wallet_credited' => $walletCredited,
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
            ? $this->creditWallet($userId, $creditDiff, $subscription->id, ucfirst($direction).' credit: '.$newPackage->name)
            : false;

        return response()->json([
            'message'         => ucfirst($direction).'d successfully.',
            'subscription'    => $this->formatSubscription($subscription->fresh('package')),
            'wallet_credited' => $walletCredited,
        ]);
    }

    private function creditWallet(string $userId, float $amount, string $subscriptionId, string $description, bool $activateCreditBuffer = false): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $walletUrl   = rtrim(config('services.wallet_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $walletUrl || ! $internalKey) {
            Log::error('Wallet credit skipped — wallet_url/internal_key not configured.', ['user_id' => $userId]);
            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->post("{$walletUrl}/api/internal/wallet/credit", [
                'user_id'                => $userId,
                'amount'                 => $amount,
                'description'            => $description,
                'reference_type'         => 'subscription',
                'reference_id'           => $subscriptionId,
                'activate_credit_buffer' => $activateCreditBuffer,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Wallet credit failed: '.$e->getMessage(), ['user_id' => $userId, 'subscription_id' => $subscriptionId]);
            return false;
        }
    }

    private function createInvoiceAfterResponse(string $userId, string $subscriptionId, Package $package, string $currency, string $transactionId): void
    {
        $billingUrl  = rtrim(config('services.billing_url'), '/');
        $internalKey = config('services.internal_key');
        $amount      = (float) $package->monthly_price_usd;
        $packageName = $package->name;

        dispatch(function () use ($billingUrl, $internalKey, $userId, $subscriptionId, $packageName, $amount, $currency, $transactionId) {
            if (! $billingUrl || ! $internalKey) {
                return;
            }

            try {
                Http::withHeaders([
                    'X-Internal-Service-Key' => $internalKey,
                    'Accept'                 => 'application/json',
                ])->timeout(15)->post("{$billingUrl}/api/internal/invoices/create", [
                    'user_id'         => $userId,
                    'subscription_id' => $subscriptionId,
                    'description'     => 'Subscription: '.$packageName,
                    'amount'          => $amount,
                    'currency'        => $currency,
                    'transaction_id'  => $transactionId,
                ]);
            } catch (\Exception $e) {
                Log::error('Invoice creation failed: '.$e->getMessage(), ['user_id' => $userId, 'subscription_id' => $subscriptionId]);
            }
        })->afterResponse();
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
