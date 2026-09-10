<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Transaction;
use App\Services\BkashGateway;
use App\Services\StripeGateway;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;

/**
 * Shared by TopupController (user-facing) and PaymentInternalController
 * (called by Subscription Service) — both need the same "create a pending
 * Transaction, then create the matching gateway Checkout Session" sequence,
 * for either Stripe (beginCheckout) or bKash (beginBkashCheckout).
 */
trait CreatesCheckoutSessions
{
    /** @return array{transaction: Transaction, checkout_url: ?string, error: ?string} */
    private function beginCheckout(
        StripeGateway $stripe,
        string $userId,
        string $type,
        float $amount,
        string $currency,
        string $description,
        array $metadata,
        // The browser's own Origin, when one is available (topup gets it directly;
        // subscribe/upgrade forward it through from subscription-service, since it's
        // otherwise lost on that server-to-server hop). Redirecting back to whatever
        // origin actually initiated checkout — rather than always the hardcoded
        // FRONTEND_URL — is what makes the post-payment callback page work at all on
        // any domain other than that one (confirmed broken live for staging.alveta.ai
        // during today's Stripe go-live test: the callback loaded on the wrong origin
        // with no auth token available there to verify the payment).
        ?string $origin = null,
    ): array {
        $idempotencyKey = (string) Str::uuid();

        $transaction = Transaction::create([
            'user_id'         => $userId,
            'type'            => $type,
            'status'          => 'pending',
            'amount'          => $amount,
            'currency'        => $currency,
            'gateway'         => 'stripe',
            'idempotency_key' => $idempotencyKey,
            'description'     => $description,
            'metadata'        => $metadata,
        ]);

        $frontendUrl = rtrim($origin ?: (string) config('services.frontend_url'), '/');
        $returnType  = $type === 'wallet_topup' ? 'topup' : 'subscription';

        try {
            $session = $stripe->createCheckoutSession(
                $amount,
                $currency,
                $description,
                "{$frontendUrl}/billing/checkout-callback?type={$returnType}&status=success&session_id={CHECKOUT_SESSION_ID}",
                "{$frontendUrl}/billing/checkout-callback?type={$returnType}&status=cancelled",
                array_merge($metadata, ['transaction_id' => $transaction->id, 'user_id' => $userId]),
                $idempotencyKey,
            );
        } catch (ApiErrorException $e) {
            $transaction->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at'     => now(),
            ]);

            return ['transaction' => $transaction, 'checkout_url' => null, 'error' => $e->getMessage()];
        }

        $transaction->update(['gateway_reference' => $session->id]);

        return ['transaction' => $transaction, 'checkout_url' => $session->url, 'error' => null];
    }

    /**
     * bKash's response shape (payment_id/bkash_url, no Session object) doesn't
     * match Stripe's, and it settles only in BDT — so this is a parallel
     * method rather than a forced shared abstraction with beginCheckout().
     * bKash also has no `{PLACEHOLDER}` substitution like Stripe's
     * success_url — it appends its own `paymentID`/`status` query params to
     * whatever callback_url is given, whatever that URL already contains.
     *
     * @return array{transaction: Transaction, checkout_url: ?string, error: ?string}
     */
    private function beginBkashCheckout(
        BkashGateway $bkash,
        string $userId,
        string $type,
        float $amountUsd,
        string $description,
        array $metadata,
        ?float $fixedAmountBdt = null,
        // Same reasoning as beginCheckout()'s $origin above — bKash's own
        // callback_url needs to land back on whichever frontend actually
        // started checkout. Missed when this method was first built (only
        // Stripe's got the fix): confirmed live, a bKash purchase started
        // from staging.alveta.ai redirected to app.alveta.ai/billing/checkout-callback
        // after payment, where the user has no session at all — that page
        // then bounced them to app.alveta.ai/login instead of completing.
        ?string $origin = null,
    ): array {
        $idempotencyKey = (string) Str::uuid();

        $transaction = Transaction::create([
            'user_id'         => $userId,
            'type'            => $type,
            'status'          => 'pending',
            'amount'          => $amountUsd,
            'currency'        => 'USD',
            'gateway'         => 'bkash',
            'exchange_rate'   => config('services.bkash.usd_to_bdt_rate'),
            'idempotency_key' => $idempotencyKey,
            'description'     => $description,
            // is_sandbox is read back by anything that touches this payment
            // again later with no browser Origin of its own to check — the
            // reconciliation sweep and admin refunds (see RefundService,
            // ReconcileBkashPaymentJob) — since sandbox/live must match
            // whichever mode actually created this paymentID, not whatever
            // mode a later, unrelated request happens to be in.
            'metadata'        => array_merge($metadata, ['is_sandbox' => $bkash->isSandbox()]),
        ]);

        $frontendUrl = rtrim($origin ?: (string) config('services.frontend_url'), '/');
        $returnType  = $type === 'wallet_topup' ? 'topup' : 'subscription';
        $callbackUrl = "{$frontendUrl}/billing/checkout-callback?type={$returnType}";
        $fullMetadata = array_merge($metadata, ['transaction_id' => $transaction->id, 'user_id' => $userId]);

        $result = $bkash->createCheckoutSession($amountUsd, $description, $callbackUrl, $fullMetadata, $fixedAmountBdt);

        if ($result['error']) {
            $transaction->update([
                'status'        => 'failed',
                'error_message' => $result['error'],
                'failed_at'     => now(),
            ]);

            return ['transaction' => $transaction, 'checkout_url' => null, 'error' => $result['error']];
        }

        $transaction->update([
            'gateway_reference' => $result['payment_id'],
            // Merged onto $transaction->metadata (not just $fullMetadata) so the
            // is_sandbox flag set at creation above survives this update instead
            // of being silently dropped.
            'metadata'          => array_merge($transaction->metadata ?? [], $fullMetadata, ['amount_bdt' => $result['amount_bdt']]),
        ]);

        return ['transaction' => $transaction, 'checkout_url' => $result['bkash_url'], 'error' => null];
    }
}
