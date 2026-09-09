<?php

namespace App\Services;

use App\Models\Package;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Everything that happens once a package purchase is known to be paid for —
 * shared by SubscriptionController::subscribe() (wallet path, called
 * synchronously right after the wallet debit succeeds) and
 * SubscriptionActivationController::activate() (card path, called once
 * Payment Service's Checkout Session is verified paid). Callers are
 * responsible for their own "already subscribed?" guard beforehand — the
 * two paths need to handle that case differently (a user-facing 409 before
 * charging vs. a defensive skip-and-log after a webhook fires late).
 */
class PackageActivationService
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private AuthServiceClient $authClient,
        private NotificationClient $notificationClient,
    ) {}

    public function activate(string $userId, Package $package, string $transactionId, string $currency): UserSubscription
    {
        // No stored PaymentMethod record to reference yet (Phase 1) — payment_method_id
        // stays null. The raw payment token/checkout session isn't persisted here (it's
        // not a UUID, and the column is typed uuid) — Payment Service's own Transaction
        // row is the source of truth for gateway details.
        $subscription = $this->subscriptions->subscribe(
            $userId,
            $package,
            $transactionId,
            $currency,
            $this->exchangeRateFor($currency, $package),
            null,
        );

        $this->creditWallet(
            $userId,
            (float) $package->monthly_wallet_credit_usd,
            $subscription->id,
            'Subscription credit: '.$package->name,
            creditLimit: $package->creditBufferAmount(),
        );

        $this->createInvoiceAfterResponse($userId, $subscription->id, $package, $currency, $transactionId);

        return $subscription;
    }

    /**
     * What this specific package purchase's currency actually converts at —
     * the ratio the admin's own fixed monthly_price_bdt/monthly_price_usd
     * implies, NOT a live currency_rates lookup. This is a permanent snapshot
     * (user_subscriptions.exchange_rate is never recomputed later, same rule
     * as every other rate snapshot in this app), so it needs to record what
     * was truly charged for this transaction, not today's admin-configured
     * rate which could change tomorrow. Was previously hardcoded to
     * 1.000000 regardless of currency — harmless while BDT was never actually
     * reachable end-to-end, but wrong now that it is.
     */
    private function exchangeRateFor(string $currency, Package $package): float
    {
        $usd = (float) $package->monthly_price_usd;

        if ($currency === 'USD' || $usd <= 0 || $package->monthly_price_bdt === null) {
            return 1.000000;
        }

        return round((float) $package->monthly_price_bdt / $usd, 6);
    }

    /**
     * Also called directly by SubscriptionController (upgrade/renewal credits).
     * $creditLimit is the package's computed buffer amount (Package::creditBufferAmount())
     * — only ever raises the wallet's ceiling, never lowers it (see WalletService::credit()).
     * Pass null for credits that aren't tied to a specific package (there are none today,
     * but keeps the contract honest for any future caller).
     */
    public function creditWallet(string $userId, float $amount, string $subscriptionId, string $description, ?float $creditLimit = null): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $walletUrl   = rtrim((string) config('services.wallet_url'), '/');
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
                'user_id'        => $userId,
                'amount'         => $amount,
                'description'    => $description,
                'reference_type' => 'subscription',
                'reference_id'   => $subscriptionId,
                'credit_limit'   => $creditLimit,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Wallet credit failed: '.$e->getMessage(), ['user_id' => $userId, 'subscription_id' => $subscriptionId]);
            return false;
        }
    }

    private function createInvoiceAfterResponse(string $userId, string $subscriptionId, Package $package, string $currency, string $transactionId): void
    {
        $billingUrl  = rtrim((string) config('services.billing_url'), '/');
        $internalKey = config('services.internal_key');
        $amount      = (float) $package->monthly_price_usd;
        $packageName = $package->name;

        dispatch(function () use ($billingUrl, $internalKey, $userId, $subscriptionId, $packageName, $amount, $currency, $transactionId) {
            if ($billingUrl && $internalKey) {
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
            }

            $user = $this->authClient->findUser($userId);
            if ($user) {
                $this->notificationClient->send(
                    'receipt',
                    $userId,
                    $user['email'],
                    ['name' => $user['name'], 'amount' => $amount, 'currency' => $currency, 'description' => 'Subscription: '.$packageName],
                    "receipt:subscription:{$transactionId}",
                );
            }
        })->afterResponse();
    }
}
