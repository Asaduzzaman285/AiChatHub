<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single place a Checkout Session actually gets turned into a credited
 * wallet or an activated subscription. Called from two independent triggers —
 * the frontend's verify-on-return call and the Stripe webhook — plus, in
 * practice, potentially several browser tabs each independently polling
 * verify-on-return for the same session. The completed-status guard alone
 * only protects against *sequential* re-entry; two calls landing at nearly
 * the same instant can both pass it before either has updated the row. The
 * claim step below closes that gap with a real row lock.
 */
class CheckoutCompletionService
{
    public function __construct(private InternalServiceClient $internal) {}

    public function complete(Transaction $transaction): void
    {
        // Claim the row under lock — only one concurrent caller can win this,
        // everyone else sees 'processing' (still claimed) or 'completed' and
        // returns immediately instead of racing into completeTopup()/
        // completeSubscription() (and double-creating a receipt) together.
        $claimed = DB::transaction(function () use ($transaction) {
            $locked = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked || in_array($locked->status, ['completed', 'processing'], true)) {
                return null;
            }

            $locked->update(['status' => 'processing']);

            return $locked;
        });

        if (! $claimed) {
            return;
        }

        $credited = match ($claimed->type) {
            'wallet_topup'          => $this->completeTopup($claimed),
            'subscription_purchase' => $this->completeSubscription($claimed),
            'subscription_upgrade'  => $this->completeUpgrade($claimed),
            default                 => false,
        };

        if (! $credited) {
            // Revert the claim, not leave it at 'processing' — 'processing' must
            // only ever mean "actively in flight right now". Reverting to
            // 'pending' lets a genuine retry (webhook redelivery, the user
            // reloading the return page) claim and try again; leaving it at
            // 'processing' would permanently block every future retry, since
            // the claim check above treats 'processing' as "someone else has
            // this."
            $claimed->update(['status' => 'pending']);
            return;
        }

        $claimed->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
     * Re-fetches and locks the row rather than trusting the $transaction
     * instance the caller already has — same reasoning as complete()'s own
     * claim step, but this method was missing it. CheckoutController::verify()
     * is polled repeatedly by the frontend (and can genuinely run twice
     * concurrently — a user reloading a payment page that looks stuck is
     * common, and bKash's tokenized flow has no webhook to fall back on) —
     * without a fresh locked read here, a losing request's executePayment()
     * rejection (bKash allows exactly one execute per payment) could call
     * cancel() against a stale in-memory $transaction still showing 'pending',
     * overwriting a status a concurrent winning request had *just* set to
     * 'completed' moments earlier. Confirmed as a real, reproducible failure
     * mode: a bKash payment that genuinely succeeded (verified independently
     * against bKash's own API) ended up 'cancelled' in this app's own ledger.
     */
    public function cancel(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $locked = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked || in_array($locked->status, ['completed', 'cancelled', 'processing'], true)) {
                return;
            }

            $locked->update(['status' => 'cancelled']);
        });
    }

    private function completeTopup(Transaction $transaction): bool
    {
        $credited = $this->internal->creditWallet(
            $transaction->user_id,
            (float) $transaction->amount,
            'Wallet top-up',
            $transaction->id,
        );

        if ($credited) {
            [$amount, $currency] = $this->realAmountAndCurrency($transaction);
            $this->internal->createReceipt($transaction->user_id, $amount, $currency, $transaction->id);
            $this->internal->sendReceiptEmail($transaction->user_id, $amount, $currency, 'Wallet top-up', "receipt:topup:{$transaction->id}");
        }

        return $credited;
    }

    /**
     * transaction->amount/currency are always this app's internal USD
     * bookkeeping value, even for a bKash payment (see beginBkashCheckout()'s
     * Transaction::create) — the real amount actually charged, whenever the
     * gateway is bKash, lives in metadata.amount_bdt (the exact BDT figure
     * either the package's own fixed sticker price, or usdToBdt()'s live
     * conversion for a top-up with no sticker). Receipts/invoices are a
     * frozen record of what really happened, so they must use this, never
     * the bookkeeping placeholder. Confirmed live: a real ৳1 bKash purchase
     * was producing a receipt/invoice reading "$1.00" — a different number
     * than the customer's own bKash statement, for any package where the
     * BDT sticker isn't numerically identical to the USD price.
     *
     * @return array{0: float, 1: string}
     */
    private function realAmountAndCurrency(Transaction $transaction): array
    {
        if ($transaction->gateway === 'bkash' && isset($transaction->metadata['amount_bdt'])) {
            return [(float) $transaction->metadata['amount_bdt'], 'BDT'];
        }

        return [(float) $transaction->amount, $transaction->currency];
    }

    private function completeSubscription(Transaction $transaction): bool
    {
        $packageSlug = $transaction->metadata['package_slug'] ?? null;
        if (! $packageSlug) {
            Log::error('Checkout completion missing package_slug in transaction metadata.', ['transaction_id' => $transaction->id]);
            return false;
        }

        $subscriptionUrl = rtrim((string) config('services.subscription_url'), '/');
        $internalKey     = config('services.internal_key');

        if (! $subscriptionUrl || ! $internalKey) {
            Log::error('Subscription activation skipped — subscription_url/internal_key not configured.', ['transaction_id' => $transaction->id]);
            return false;
        }

        try {
            [$amount, $currency] = $this->realAmountAndCurrency($transaction);

            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(20)->post("{$subscriptionUrl}/api/internal/subscriptions/activate", [
                'user_id'        => $transaction->user_id,
                'package_slug'   => $packageSlug,
                'transaction_id' => $transaction->id,
                // The real currency actually charged — realAmountAndCurrency()
                // already resolves both bKash's own Transaction-bookkeeping
                // quirk (always 'USD' there regardless of what was really
                // charged) and a card/Stripe checkout charged directly in BDT
                // (see StripeGateway::createCheckoutSession()'s $currency,
                // which IS the real one — no bookkeeping placeholder involved),
                // so subscription-service can trust it directly to (a) pick
                // between monthly_wallet_credit_usd/_bdt and (b) record the
                // SUBSCRIPTION's and INVOICE's own currency/amount as what was
                // really charged. Confirmed live: before this, a real ৳1
                // bKash purchase recorded user_subscriptions.currency='USD'
                // and an invoice of "$1.00" — silently wrong for any package
                // where the BDT sticker isn't numerically identical to the USD
                // price.
                'currency'       => $currency,
                'gateway'        => $transaction->gateway,
                'amount_bdt'     => $currency === 'BDT' ? $amount : null,
            ]);

            if (! $response->successful()) {
                return false;
            }

            $this->internal->createReceipt($transaction->user_id, $amount, $currency, $transaction->id, 'subscription_purchase');

            return true;
        } catch (\Exception $e) {
            Log::error('Subscription activation call failed: '.$e->getMessage(), ['transaction_id' => $transaction->id]);
            return false;
        }
    }

    /** Same shape as completeSubscription() — the only difference is which subscription-service endpoint gets called. */
    private function completeUpgrade(Transaction $transaction): bool
    {
        $packageSlug = $transaction->metadata['package_slug'] ?? null;
        if (! $packageSlug) {
            Log::error('Checkout completion missing package_slug in transaction metadata.', ['transaction_id' => $transaction->id]);
            return false;
        }

        $subscriptionUrl = rtrim((string) config('services.subscription_url'), '/');
        $internalKey     = config('services.internal_key');

        if (! $subscriptionUrl || ! $internalKey) {
            Log::error('Upgrade activation skipped — subscription_url/internal_key not configured.', ['transaction_id' => $transaction->id]);
            return false;
        }

        try {
            [$amount, $currency] = $this->realAmountAndCurrency($transaction);

            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(20)->post("{$subscriptionUrl}/api/internal/subscriptions/activate-upgrade", [
                'user_id'        => $transaction->user_id,
                'package_slug'   => $packageSlug,
                'transaction_id' => $transaction->id,
                'currency'       => $currency,
                'gateway'        => $transaction->gateway,
                'amount_bdt'     => $currency === 'BDT' ? $amount : null,
            ]);

            if (! $response->successful()) {
                return false;
            }

            $this->internal->createReceipt($transaction->user_id, $amount, $currency, $transaction->id, 'subscription_upgrade');

            return true;
        } catch (\Exception $e) {
            Log::error('Upgrade activation call failed: '.$e->getMessage(), ['transaction_id' => $transaction->id]);
            return false;
        }
    }
}
