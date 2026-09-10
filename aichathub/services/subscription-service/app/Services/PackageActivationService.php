<?php

namespace App\Services;

use App\Models\Currency;
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

    public function activate(string $userId, Package $package, string $transactionId, string $currency, ?float $amountBdt = null): UserSubscription
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
            $this->exchangeRateFor($currency, $package, $amountBdt),
            null,
        );

        $this->creditWallet(
            $userId,
            $this->computeWalletCredit($package, $currency),
            $subscription->id,
            'Subscription credit: '.$package->name,
            creditLimit: $package->creditBufferAmount(),
        );

        $this->createInvoiceAfterResponse($userId, $subscription->id, $package, $currency, $transactionId, $amountBdt);

        return $subscription;
    }

    /**
     * Wallet credit follows the SAME currency the customer actually paid in —
     * two independent, admin-typed catalog numbers (monthly_wallet_credit_usd
     * / monthly_wallet_credit_bdt), the same pattern already established by
     * monthly_price_usd/monthly_price_bdt, rather than one field derived
     * through a formula. A USD purchase credits monthly_wallet_credit_usd
     * directly; a BDT purchase (bKash, or now a card/Stripe checkout charged
     * in BDT — see SubscriptionController::resolveCurrency()) credits
     * monthly_wallet_credit_bdt, converted once via the active BDT conversion
     * policy into the wallet's real, canonical USD ledger amount (AI provider
     * costs are metered in USD, so the wallet's single stored balance has to
     * stay USD internally either way). useDisplayCurrency() then converts
     * that stored USD value back to BDT using *today's* rate whenever it's
     * shown to a BDT-preferring user — which reproduces
     * monthly_wallet_credit_bdt as long as the policy hasn't changed since,
     * and drifts slightly if it has, same as any live-rate display of an
     * ongoing balance.
     *
     * Keyed on the real currency actually charged, not the gateway — those
     * used to be interchangeable (only bKash ever settled BDT), but a card
     * checkout can charge BDT directly now too, so gateway alone no longer
     * tells you which credit field applies.
     *
     * An earlier version of this derived credit from a computed ratio applied
     * to whatever amount was actually charged — mathematically self-consistent,
     * but opaque and impossible for an admin to reason about or directly
     * control. This is simpler and mirrors the existing price fields exactly.
     *
     * Falls back to the flat USD catalog value whenever there's no BDT-specific
     * credit configured for this package yet, or no active BDT conversion
     * policy exists — never regresses to $0.
     */
    public function computeWalletCredit(Package $package, string $currency): float
    {
        if ($currency === 'BDT' && $package->monthly_wallet_credit_bdt !== null) {
            $bdtRate = Currency::where('code', 'BDT')->where('is_active', true)->first()?->activeRate();

            if ($bdtRate) {
                return round((float) $package->monthly_wallet_credit_bdt / (float) $bdtRate->effective_rate, 6);
            }
        }

        return (float) $package->monthly_wallet_credit_usd;
    }

    /**
     * What this specific package purchase's currency actually converts at —
     * the ratio between the USD price and the REAL BDT amount charged, NOT a
     * live currency_rates lookup. This is a permanent snapshot
     * (user_subscriptions.exchange_rate is never recomputed later, same rule
     * as every other rate snapshot in this app), so it needs to record what
     * was truly charged for this transaction, not today's admin-configured
     * rate which could change tomorrow.
     *
     * Prefers $amountBdt (the actual amount_bdt from the real bKash
     * transaction) over the package's current monthly_price_bdt catalog
     * value — they're normally identical, but $amountBdt stays correct even
     * if an admin changes the package's price after this specific purchase
     * happened. Falls back to monthly_price_bdt only when $amountBdt wasn't
     * passed (e.g. the free-package direct-activation path, which has no
     * real payment at all).
     */
    private function exchangeRateFor(string $currency, Package $package, ?float $amountBdt = null): float
    {
        $usd = (float) $package->monthly_price_usd;
        $bdt = $amountBdt ?? ($package->monthly_price_bdt !== null ? (float) $package->monthly_price_bdt : null);

        if ($currency === 'USD' || $usd <= 0 || $bdt === null) {
            return 1.000000;
        }

        return round($bdt / $usd, 6);
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

    private function createInvoiceAfterResponse(string $userId, string $subscriptionId, Package $package, string $currency, string $transactionId, ?float $amountBdt = null): void
    {
        $billingUrl  = rtrim((string) config('services.billing_url'), '/');
        $internalKey = config('services.internal_key');
        // The invoice/receipt has to show what was really charged — always
        // monthly_price_usd was wrong for a BDT purchase whenever the BDT
        // sticker isn't numerically identical to the USD price (confirmed
        // live: a real bKash purchase produced an invoice reading "$1.00"
        // for a package priced at $5/৳499 — the invoice quietly claimed a
        // completely different amount than what the customer's bKash
        // statement actually shows).
        $amount      = $currency === 'BDT' && $amountBdt !== null ? $amountBdt : (float) $package->monthly_price_usd;
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
