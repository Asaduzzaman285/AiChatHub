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
    public function subscribe(string $userId, Package $package, string $transactionId, string $currency, float $exchangeRate, string $paymentMethodId): UserSubscription
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
     * Upgrade to a higher package. Charges full new price.
     */
    public function upgrade(UserSubscription $current, Package $newPackage, string $transactionId): UserSubscription
    {
        return DB::transaction(function () use ($current, $newPackage, $transactionId) {
            $oldPackageId = $current->package_id;

            $current->update([
                'package_id'          => $newPackage->id,
                'previous_package_id' => $oldPackageId,
                'renews_at'           => now()->addDays(30),
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
     * Downgrade to a lower package. Access restriction immediate.
     */
    public function downgrade(UserSubscription $current, Package $newPackage, string $transactionId): UserSubscription
    {
        return DB::transaction(function () use ($current, $newPackage, $transactionId) {
            $oldPackageId = $current->package_id;

            $current->update([
                'package_id'          => $newPackage->id,
                'previous_package_id' => $oldPackageId,
                'renews_at'           => now()->addDays(30),
            ]);

            SubscriptionHistory::create([
                'subscription_id' => $current->id,
                'user_id'         => $current->user_id,
                'action'          => 'downgraded',
                'old_package_id'  => $oldPackageId,
                'new_package_id'  => $newPackage->id,
                'metadata'        => ['transaction_id' => $transactionId],
            ]);

            $this->publishEvent('subscription.downgraded', [
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
        return UserSubscription::with('package')
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
