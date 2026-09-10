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
            // The real currency actually charged — payment-service computes this
            // itself now (CheckoutCompletionService::realAmountAndCurrency()),
            // covering both bKash's own Transaction-bookkeeping quirk (always
            // 'USD' there regardless of what was really charged) and a genuine
            // card/Stripe checkout charged directly in BDT, so it's trustworthy
            // here without re-deriving it from 'gateway' (which stopped being a
            // reliable proxy once card could charge BDT too — a card purchase's
            // gateway is 'stripe' either way).
            'currency'       => 'nullable|string|size:3',
            'gateway'        => 'nullable|string|in:stripe,bkash',
            // The real BDT amount actually charged, used below to record the
            // subscription's own currency/exchange_rate and the invoice amount
            // as what was truly paid.
            'amount_bdt'     => 'nullable|numeric',
        ]);

        if ($this->subscriptions->getActive($data['user_id'])) {
            Log::warning('Subscription activation skipped — user already has an active subscription.', [
                'user_id'        => $data['user_id'],
                'transaction_id' => $data['transaction_id'],
            ]);

            return response()->json(['message' => 'Already active, activation skipped.', 'skipped' => true]);
        }

        $package = Package::where('slug', $data['package_slug'])->where('is_active', true)->firstOrFail();
        $amountBdt = isset($data['amount_bdt']) ? (float) $data['amount_bdt'] : null;
        // Confirmed live: before 'currency' carried the real value here, a real
        // ৳1 bKash purchase recorded user_subscriptions.currency='USD' and
        // exchange_rate=1.0, silently wrong for any package whose BDT sticker
        // isn't numerically identical to its USD price.
        $realCurrency = strtoupper($data['currency'] ?? 'USD') === 'BDT' ? 'BDT' : 'USD';

        $subscription = $this->activation->activate(
            $data['user_id'],
            $package,
            $data['transaction_id'],
            $realCurrency,
            $amountBdt,
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
            'gateway'        => 'nullable|string|in:stripe,bkash',
            'amount_bdt'     => 'nullable|numeric',
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

        $amountBdt = isset($data['amount_bdt']) ? (float) $data['amount_bdt'] : null;
        // Same reasoning as activate() above — 'currency' is payment-service's
        // own already-resolved real currency, trustworthy directly.
        $realCurrency = strtoupper($data['currency'] ?? 'USD') === 'BDT' ? 'BDT' : 'USD';
        $invoiceAmount = $realCurrency === 'BDT' && $amountBdt !== null ? $amountBdt : (float) $package->monthly_price_usd;

        $subscription = $this->subscriptions->applyUpgrade($current, $package, $data['transaction_id']);
        // Must use the per-upgrade transaction_id, not $subscription->id, as the credit
        // reference — creditWallet()'s idempotency guard keys on (subscription, credit)
        // pairs, and the subscription's id never changes across upgrades. Using it here
        // made every paid upgrade look like a duplicate of the original purchase credit
        // and silently no-op (same bug class already fixed for renewals — see
        // ProcessRenewalJob.php — but missed here; caught live 2026-08-06).
        $credit = $this->activation->computeWalletCredit($package, $realCurrency);
        $this->activation->creditWallet($data['user_id'], $credit, $data['transaction_id'], 'Upgrade credit: '.$package->name, $package->creditBufferAmount());

        $billingUrl  = rtrim((string) config('services.billing_url'), '/');
        $internalKey = config('services.internal_key');
        if ($billingUrl && $internalKey) {
            dispatch(function () use ($billingUrl, $internalKey, $data, $package, $subscription, $realCurrency, $invoiceAmount) {
                try {
                    \Illuminate\Support\Facades\Http::withHeaders([
                        'X-Internal-Service-Key' => $internalKey,
                        'Accept'                 => 'application/json',
                    ])->timeout(15)->post("{$billingUrl}/api/internal/invoices/create", [
                        'user_id'         => $data['user_id'],
                        'subscription_id' => $subscription->id,
                        'description'     => 'Upgrade: '.$package->name,
                        'amount'          => $invoiceAmount,
                        'currency'        => $realCurrency,
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
