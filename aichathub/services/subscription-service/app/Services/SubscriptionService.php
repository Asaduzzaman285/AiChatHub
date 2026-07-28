<?php

namespace App\Services;

use App\Models\Package;
use App\Models\SubscriptionHistory;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SubscriptionService
{
    /**
     * Subscribe a user to a new package.
     * Called after payment is confirmed.
     */
    public function subscribe(string $userId, Package $package, string $transactionId, string $currency, float $exchangeRate, ?string $paymentMethodId = null): UserSubscription
    {
        return DB::transaction(function () use ($userId, $package, $transactionId, $currency, $exchangeRate, $paymentMethodId) {
            $now = now();

            $subscription = UserSubscription::create([
                'user_id'           => $userId,
                'package_id'        => $package->id,
                'payment_method_id' => $paymentMethodId,
                'status'            => 'active',
                'auto_renew'        => true,
                'currency'          => $currency,
                'exchange_rate'     => $exchangeRate,
                'activated_at'      => $now,
                'renews_at'         => $now->copy()->addDays(30),
            ]);

            SubscriptionHistory::create([
                'subscription_id' => $subscription->id,
                'user_id'         => $userId,
                'action'          => 'purchased',
                'new_package_id'  => $package->id,
                'new_status'      => 'active',
                'metadata'        => ['transaction_id' => $transactionId],
            ]);

            // Publish event — Wallet Service and Billing Service listen
            $this->publishEvent('subscription.purchased', [
                'user_id'         => $userId,
                'subscription_id' => $subscription->id,
                'package_id'      => $package->id,
                'amount'          => $package->monthly_wallet_credit_usd,
                'currency'        => $currency,
                'exchange_rate'   => $exchangeRate,
                'transaction_id'  => $transactionId,
            ]);

            return $subscription;
        });
    }

    /**
     * Extends a subscription for another cycle after a successful renewal charge.
     * $switchToPackage is passed when a scheduled downgrade takes effect this
     * cycle — ProcessRenewalJob resolves it and charges/credits based on it
     * before calling here, this just performs the actual package switch.
     */
    public function renewSuccess(UserSubscription $subscription, ?Package $switchToPackage = null): UserSubscription
    {
        return DB::transaction(function () use ($subscription, $switchToPackage) {
            $oldStatus    = $subscription->status;
            $oldPackageId = $subscription->package_id;

            $updates = [
                'status'      => 'active',
                'past_due_at' => null,
                'renews_at'   => now()->addDays(30),
            ];

            if ($switchToPackage) {
                $updates['package_id']           = $switchToPackage->id;
                $updates['previous_package_id']  = $oldPackageId;
                $updates['scheduled_package_id'] = null;
            }

            $subscription->update($updates);

            SubscriptionHistory::create([
                'subscription_id' => $subscription->id,
                'user_id'         => $subscription->user_id,
                'action'          => $switchToPackage ? 'downgraded' : 'renewed',
                'old_status'      => $oldStatus,
                'new_status'      => 'active',
                'old_package_id'  => $switchToPackage ? $oldPackageId : null,
                'new_package_id'  => $switchToPackage ? $switchToPackage->id : null,
            ]);

            return $subscription;
        });
    }

    /** Marks a subscription past_due after a failed renewal attempt that will be retried. */
    public function markPastDue(UserSubscription $subscription): UserSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $oldStatus = $subscription->status;

            $subscription->update([
                'status'      => 'past_due',
                'past_due_at' => $subscription->past_due_at ?? now(),
            ]);

            SubscriptionHistory::create([
                'subscription_id' => $subscription->id,
                'user_id'         => $subscription->user_id,
                'action'          => 'renewal_failed',
                'old_status'      => $oldStatus,
                'new_status'      => 'past_due',
            ]);

            return $subscription;
        });
    }

    /** Cancels a subscription after the final renewal retry has also failed. */
    public function cancelForFailedRenewal(UserSubscription $subscription): UserSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $oldStatus = $subscription->status;

            $subscription->update([
                'status'               => 'cancelled',
                'cancelled_at'         => now(),
                'cancellation_reason'  => 'Renewal payment failed after 3 attempts',
            ]);

            SubscriptionHistory::create([
                'subscription_id' => $subscription->id,
                'user_id'         => $subscription->user_id,
                'action'          => 'cancelled',
                'old_status'      => $oldStatus,
                'new_status'      => 'cancelled',
                'metadata'        => ['reason' => 'renewal_failed'],
            ]);

            return $subscription;
        });
    }

    /**
     * Applies an upgrade immediately — package switch, fresh 30-day cycle,
     * clears any pending scheduled downgrade (an upgrade supersedes it since
     * the whole point of scheduling was "wait until the cycle ends," and this
     * upgrade just started a brand new one). Caller charges the full new
     * price and credits the full new wallet allowance separately — this only
     * performs the subscription-record switch.
     */
    public function applyUpgrade(UserSubscription $current, Package $newPackage, string $transactionId): UserSubscription
    {
        return DB::transaction(function () use ($current, $newPackage, $transactionId) {
            $oldPackageId = $current->package_id;

            $current->update([
                'package_id'           => $newPackage->id,
                'previous_package_id'  => $oldPackageId,
                'scheduled_package_id' => null,
                'renews_at'            => now()->addDays(30),
            ]);

            SubscriptionHistory::create([
                'subscription_id' => $current->id,
                'user_id'         => $current->user_id,
                'action'          => 'upgraded',
                'old_package_id'  => $oldPackageId,
                'new_package_id'  => $newPackage->id,
                'metadata'        => ['transaction_id' => $transactionId],
            ]);

            $this->publishEvent('subscription.upgraded', [
                'user_id'         => $current->user_id,
                'subscription_id' => $current->id,
                'old_package_id'  => $oldPackageId,
                'new_package_id'  => $newPackage->id,
                'amount'          => $newPackage->monthly_wallet_credit_usd,
                'currency'        => $current->currency,
                'transaction_id'  => $transactionId,
            ]);

            return $current->fresh();
        });
    }

    /**
     * Schedules a downgrade for the next renewal — no immediate change, no
     * money moves. The user keeps their current tier's access until the
     * already-paid-for period ends; ProcessRenewalJob applies it (via
     * renewSuccess()'s $switchToPackage) once the cycle actually rolls over.
     * Overwrites any previously-scheduled target with the latest choice.
     */
    public function scheduleDowngrade(UserSubscription $current, Package $newPackage): UserSubscription
    {
        return DB::transaction(function () use ($current, $newPackage) {
            $current->update(['scheduled_package_id' => $newPackage->id]);

            SubscriptionHistory::create([
                'subscription_id' => $current->id,
                'user_id'         => $current->user_id,
                'action'          => 'downgrade_scheduled',
                'old_package_id'  => $current->package_id,
                'new_package_id'  => $newPackage->id,
            ]);

            $this->publishEvent('subscription.downgrade_scheduled', [
                'user_id'          => $current->user_id,
                'subscription_id'  => $current->id,
                'current_package_id' => $current->package_id,
                'scheduled_package_id' => $newPackage->id,
                'effective_at'     => $current->renews_at,
            ]);

            return $current->fresh();
        });
    }

    /**
     * Cancel at end of billing cycle (recommended).
     */
    public function cancelAtEndOfCycle(UserSubscription $subscription, string $reason = ''): void
    {
        $subscription->update([
            'auto_renew'           => false,
            'cancellation_reason'  => $reason,
        ]);

        SubscriptionHistory::create([
            'subscription_id' => $subscription->id,
            'user_id'         => $subscription->user_id,
            'action'          => 'cancelled',
            'old_status'      => 'active',
            'new_status'      => 'active',
            'metadata'        => ['type' => 'end_of_cycle', 'reason' => $reason],
        ]);

        $this->publishEvent('subscription.cancelled', [
            'user_id'           => $subscription->user_id,
            'subscription_id'   => $subscription->id,
            'cancellation_type' => 'end_of_cycle',
            'access_until'      => $subscription->renews_at,
        ]);
    }

    /**
     * Get active subscription for a user — used by Internal API.
     */
    public function getActive(string $userId): ?UserSubscription
    {
        return UserSubscription::with(['package', 'scheduledPackage'])
            ->where('user_id', $userId)
            ->whereIn('status', ['active', 'past_due'])
            ->first();
    }

    private function publishEvent(string $event, array $payload): void
    {
        // Publish to Redis channel — other services listen via queue workers
        \Illuminate\Support\Facades\Redis::publish(
            'subscription-events',
            json_encode(['event' => $event, 'payload' => $payload, 'timestamp' => now()->toIso8601String()])
        );
    }
}
