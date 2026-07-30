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
     * Hands back a Stripe/bKash Checkout URL — activation is deferred until
     * the payment is verified (SubscriptionActivationController::activate(),
     * called by Payment Service once the Checkout Session completes). A free
     * ($0) package is the only case that activates synchronously, since
     * there's genuinely no payment to collect.
     *
     * Wallet is deliberately not a payment_source here — wallet balance
     * (whether topped up directly or granted as a plan allowance) is meant
     * for AI-usage spending only. Subscribing, upgrading, and renewing all
     * always move real money through a gateway; this was already true for
     * upgrades (doUpgrade() below) and is now true for the first purchase too.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_slug'   => 'required|string|exists:packages,slug',
            'payment_source' => 'required|in:card,bkash',
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

        if ($price > 0) {
            $checkoutUrl = $this->createGatewayCheckout($userId, $price, $currency, $package, $data['payment_source']);

            if (! $checkoutUrl) {
                return response()->json(['message' => 'Could not start checkout. Please try again.', 'error' => 'checkout_failed'], 502);
            }

            return response()->json(['checkout_url' => $checkoutUrl]);
        }

        // Free package — no payment involved at all, activates immediately.
        $transactionId = (string) Str::uuid();
        $subscription  = $this->activation->activate($userId, $package, $transactionId, $currency);

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
        $rules = ['package_slug' => 'required|string|exists:packages,slug'];
        if ($direction === 'upgrade') {
            // Wallet is deliberately not an option here (unlike subscribe()) — wallet
            // balance, whether topped up directly or granted as a plan allowance, is
            // meant for AI-usage spending, not for self-funding a tier upgrade. Letting
            // it pay for upgrades would let a user "upgrade" using credit that was
            // itself a free perk, with no real new payment ever happening.
            $rules['payment_source'] = 'required|in:card,bkash';
            $rules['currency']       = 'nullable|string|size:3';
        }
        $data = $request->validate($rules);

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

        return $direction === 'upgrade'
            ? $this->doUpgrade($userId, $current, $newPackage, $data)
            : $this->doDowngrade($userId, $current, $newPackage);
    }

    /**
     * Upgrade charges the full new plan price immediately (not a difference —
     * see HANDOFF for the reasoning) via a real payment gateway (card/bKash
     * Checkout) — never wallet balance, deliberately (see the payment_source
     * validation comment above). A free ($0) package needs no charge at all
     * and applies immediately, same as subscribe()'s equivalent case.
     */
    private function doUpgrade(string $userId, UserSubscription $current, Package $newPackage, array $data): JsonResponse
    {
        $currency = $data['currency'] ?? 'USD';
        $price    = (float) $newPackage->monthly_price_usd;

        if ($price > 0) {
            $checkoutUrl = $this->createGatewayCheckout($userId, $price, $currency, $newPackage, $data['payment_source'], 'subscription_upgrade');

            if (! $checkoutUrl) {
                return response()->json(['message' => 'Could not start checkout. Please try again.', 'error' => 'checkout_failed'], 502);
            }

            return response()->json(['checkout_url' => $checkoutUrl]);
        }

        $transactionId  = (string) Str::uuid();
        $subscription   = $this->subscriptions->applyUpgrade($current, $newPackage, $transactionId);
        $walletCredited = $this->activation->creditWallet($userId, (float) $newPackage->monthly_wallet_credit_usd, $subscription->id, 'Upgrade credit: '.$newPackage->name, $newPackage->creditBufferAmount());

        return response()->json([
            'message'         => 'Upgraded successfully.',
            'subscription'    => $this->formatSubscription($subscription->fresh('package')),
            'wallet_credited' => $walletCredited,
        ], 201);
    }

    /**
     * Downgrade never moves money or changes access immediately — it's just
     * scheduled. The user keeps their current tier until the period they
     * already paid for ends; ProcessRenewalJob applies it at the next renewal.
     */
    private function doDowngrade(string $userId, UserSubscription $current, Package $newPackage): JsonResponse
    {
        $subscription = $this->subscriptions->scheduleDowngrade($current, $newPackage);

        return response()->json([
            'message'      => "You'll switch to {$newPackage->name} on your next renewal.",
            'subscription' => $this->formatSubscription($subscription->fresh(['package', 'scheduledPackage'])),
        ]);
    }

    /**
     * Asks Payment Service to open a Checkout Session (Stripe or bKash) for
     * this package purchase or upgrade. $paymentSource is the user-facing
     * choice ("card"/"bkash", validated in changePackage()/subscribe()) —
     * payment-service's internal API expects the real gateway name instead
     * ("stripe" is what actually processes a card), so it's translated here,
     * the one place that talks to that API, rather than requiring every
     * caller to know the mapping. Found live 2026-07-30: a card-funded
     * checkout had been sending the literal string "card" as `gateway` since
     * this was built, which payment-service's validation rejects outright
     * (422) — bKash happened to match by coincidence ("bkash" == "bkash"),
     * which is why only that path had ever actually been exercised before.
     */
    private function createGatewayCheckout(string $userId, float $amount, string $currency, Package $package, string $paymentSource, string $type = 'subscription_purchase'): ?string
    {
        $paymentUrl  = rtrim(config('services.payment_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $paymentUrl || ! $internalKey) {
            Log::error('Checkout skipped — payment_url/internal_key not configured.', ['user_id' => $userId]);
            return null;
        }

        $gateway = $paymentSource === 'card' ? 'stripe' : $paymentSource;

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(20)->post("{$paymentUrl}/api/internal/payments/checkout", [
                'user_id'      => $userId,
                'amount'       => $amount,
                'currency'     => $currency,
                'gateway'      => $gateway,
                'type'         => $type,
                'description'  => ($type === 'subscription_upgrade' ? 'Upgrade: ' : 'Subscription: ').$package->name,
                'package_slug' => $package->slug,
            ]);

            if (! $response->successful()) {
                // Previously silent — $response->successful() === false doesn't throw,
                // so this branch logged nothing at all, which is exactly why this bug
                // needed manual reproduction to diagnose instead of a log line.
                Log::error('Checkout creation rejected by payment-service', [
                    'user_id' => $userId, 'gateway' => $gateway, 'status' => $response->status(), 'body' => $response->body(),
                ]);
                return null;
            }

            return $response->json('checkout_url');
        } catch (\Exception $e) {
            Log::error('Checkout creation failed: '.$e->getMessage(), ['user_id' => $userId, 'gateway' => $gateway]);
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
            // Set only when a downgrade is scheduled — takes effect at renews_at.
            'scheduled_package' => $subscription->scheduled_package_id && $subscription->scheduledPackage ? [
                'id'   => $subscription->scheduledPackage->id,
                'name' => $subscription->scheduledPackage->name,
                'slug' => $subscription->scheduledPackage->slug,
            ] : null,
        ];
    }
}
