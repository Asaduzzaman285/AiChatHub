<?php

namespace App\Jobs;

use App\Models\Package;
use App\Models\RenewalAttempt;
use App\Models\UserSubscription;
use App\Services\AuthServiceClient;
use App\Services\NotificationClient;
use App\Services\PackageActivationService;
use App\Services\PaymentChargeService;
use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Charges a subscription's next cycle: wallet first, then the user's saved
 * default card (a background job has no browser to send anyone through
 * Stripe Checkout with). On failure, retries up to 3 attempts total, 24h
 * apart — self-rescheduling rather than a separate RetryRenewalJob class,
 * since "the same job, one attempt later" is simpler than two classes with
 * near-identical bodies. After the 3rd failure the subscription is cancelled.
 *
 * Laravel's own queue `tries` is explicitly pinned to 1, not inherited from
 * the worker's `--tries` flag — retry timing here is a 24-hour business rule
 * handled explicitly via a delayed re-dispatch, not a transient-failure
 * retry. This matters: a wallet/payment HTTP call in this environment can
 * legitimately take 15s+ to time out, and two of them in sequence (wallet
 * then card fallback) can cross the queue worker's own per-job timeout —
 * letting the worker auto-retry on top of that would run the whole charge
 * attempt twice.
 */
class ProcessRenewalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $subscriptionId,
        public int $attemptNumber = 1,
    ) {}

    public function handle(
        SubscriptionService $subscriptions,
        PaymentChargeService $charges,
        PackageActivationService $activation,
    ): void {
        $subscription = UserSubscription::with('package')->find($this->subscriptionId);

        // Cancelled, package-changed, or auto-renew turned off since this was
        // scheduled — nothing to do, not a failure.
        if (! $subscription || ! $subscription->auto_renew || ! in_array($subscription->status, ['active', 'past_due'], true)) {
            return;
        }

        $package = $subscription->package;
        $price   = (float) $package->monthly_price_usd;

        // Deterministic, not a fresh UUID per call — must stay stable across
        // any re-run of this exact (subscription, attempt) pair for
        // wallet/payment idempotency to actually mean anything here. Laravel's
        // Str helper has no uuid5() (that's Ramsey's own API, used directly
        // here) — namespace is RFC 4122's well-known NAMESPACE_DNS UUID,
        // an arbitrary but fixed choice that must never change.
        $transactionId = (string) \Ramsey\Uuid\Uuid::uuid5('6ba7b810-9dad-11d1-80b4-00c04fd430c8', "aichathub-renewal:{$this->subscriptionId}:{$this->attemptNumber}");

        $charged = $price <= 0
            || $charges->chargeWallet($subscription->user_id, $price, $transactionId, 'Renewal: '.$package->name)
            || $charges->chargeSavedCard($subscription->user_id, $price, $subscription->currency, $transactionId, 'Renewal: '.$package->name);

        RenewalAttempt::create([
            'subscription_id' => $subscription->id,
            'user_id'         => $subscription->user_id,
            'attempt_number'  => $this->attemptNumber,
            'scheduled_at'    => now(),
            'attempted_at'    => now(),
            'success'         => $charged,
            'error_message'   => $charged ? null : 'Wallet balance insufficient and no working saved card on file.',
            'transaction_id'  => $charged ? $transactionId : null,
        ]);

        $charged
            ? $this->onSuccess($subscriptions, $activation, $subscription, $package, $transactionId)
            : $this->onFailure($subscriptions, $subscription, $package);
    }

    private function onSuccess(SubscriptionService $subscriptions, PackageActivationService $activation, UserSubscription $subscription, Package $package, string $transactionId): void
    {
        $subscriptions->renewSuccess($subscription);

        // Must use the per-cycle transactionId, not $subscription->id, as the credit
        // reference — creditWallet()'s idempotency guard keys on (subscription, credit)
        // pairs, and the subscription's id never changes between billing cycles. Using
        // it here would make every renewal after the first look like a duplicate of the
        // original purchase credit and silently no-op (caught live 2026-07-23).
        $activation->creditWallet(
            $subscription->user_id,
            (float) $package->monthly_wallet_credit_usd,
            $transactionId,
            'Renewal credit: '.$package->name,
        );

        $this->createInvoice($subscription, $package, $transactionId);
    }

    private function onFailure(SubscriptionService $subscriptions, UserSubscription $subscription, Package $package): void
    {
        if ($this->attemptNumber >= 3) {
            $subscriptions->cancelForFailedRenewal($subscription);
            $this->notifyRenewalFailed($subscription, $package);
            return;
        }

        $subscriptions->markPastDue($subscription);
        $this->notifyRenewalFailed($subscription, $package);

        static::dispatch($subscription->id, $this->attemptNumber + 1)->delay(now()->addHours(24));
    }

    private function createInvoice(UserSubscription $subscription, Package $package, string $transactionId): void
    {
        $billingUrl  = rtrim((string) config('services.billing_url'), '/');
        $internalKey = config('services.internal_key');

        if (! $billingUrl || ! $internalKey) {
            return;
        }

        try {
            Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(15)->post("{$billingUrl}/api/internal/invoices/create", [
                'user_id'         => $subscription->user_id,
                'subscription_id' => $subscription->id,
                'description'     => 'Renewal: '.$package->name,
                'amount'          => (float) $package->monthly_price_usd,
                'currency'        => $subscription->currency,
                'transaction_id'  => $transactionId,
            ]);
        } catch (\Exception $e) {
            Log::error('Renewal invoice creation failed: '.$e->getMessage(), ['subscription_id' => $subscription->id]);
        }
    }

    private function notifyRenewalFailed(UserSubscription $subscription, Package $package): void
    {
        $user = app(AuthServiceClient::class)->findUser($subscription->user_id);
        if (! $user) {
            return;
        }

        app(NotificationClient::class)->send(
            'renewal_failed',
            $subscription->user_id,
            $user['email'],
            ['name' => $user['name'], 'package_name' => $package->name],
            "renewal_failed:{$subscription->id}:attempt:{$this->attemptNumber}",
        );
    }
}
