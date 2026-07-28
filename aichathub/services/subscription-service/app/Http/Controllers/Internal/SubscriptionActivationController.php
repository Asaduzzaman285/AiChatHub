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

    /**
     * POST /internal/subscriptions/activate-upgrade
     * Called by Payment Service once a card/bKash-funded upgrade's Checkout
     * Session is verified paid — the wallet-funded path applies immediately
     * in SubscriptionController::doUpgrade() instead, this only covers the
     * deferred card/bkash path. Mirrors activate()'s defensive-skip shape:
     * if the user is already on the target package (a wallet-funded upgrade
     * elsewhere won the race), skip rather than error, since money has
     * already moved by this point.
     */
    public function activateUpgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'        => 'required|uuid',
            'package_slug'   => 'required|string|exists:packages,slug',
            'transaction_id' => 'required|uuid',
            'currency'       => 'nullable|string|size:3',
        ]);

        $current = $this->subscriptions->getActive($data['user_id']);

        if (! $current) {
            Log::error('Upgrade activation failed — user has no active subscription to upgrade.', [
                'user_id' => $data['user_id'], 'transaction_id' => $data['transaction_id'],
            ]);
            return response()->json(['message' => 'No active subscription to upgrade.', 'skipped' => true]);
        }

        $package = Package::where('slug', $data['package_slug'])->where('is_active', true)->firstOrFail();

        if ($package->id === $current->package_id) {
            Log::warning('Upgrade activation skipped — user already on the target package.', [
                'user_id' => $data['user_id'], 'transaction_id' => $data['transaction_id'],
            ]);
            return response()->json(['message' => 'Already on this package, activation skipped.', 'skipped' => true]);
        }

        $subscription = $this->subscriptions->applyUpgrade($current, $package, $data['transaction_id']);
        $this->activation->creditWallet($data['user_id'], (float) $package->monthly_wallet_credit_usd, $subscription->id, 'Upgrade credit: '.$package->name, $package->creditBufferAmount());

        $billingUrl  = rtrim((string) config('services.billing_url'), '/');
        $internalKey = config('services.internal_key');
        if ($billingUrl && $internalKey) {
            dispatch(function () use ($billingUrl, $internalKey, $data, $package, $subscription) {
                try {
                    \Illuminate\Support\Facades\Http::withHeaders([
                        'X-Internal-Service-Key' => $internalKey,
                        'Accept'                 => 'application/json',
                    ])->timeout(15)->post("{$billingUrl}/api/internal/invoices/create", [
                        'user_id'         => $data['user_id'],
                        'subscription_id' => $subscription->id,
                        'description'     => 'Upgrade: '.$package->name,
                        'amount'          => (float) $package->monthly_price_usd,
                        'currency'        => $data['currency'] ?? 'USD',
                        'transaction_id'  => $data['transaction_id'],
                    ]);
                } catch (\Exception $e) {
                    Log::error('Upgrade invoice creation failed: '.$e->getMessage(), ['subscription_id' => $subscription->id]);
                }
            })->afterResponse();
        }

        return response()->json(['subscription_id' => $subscription->id, 'skipped' => false], 201);
    }
}
