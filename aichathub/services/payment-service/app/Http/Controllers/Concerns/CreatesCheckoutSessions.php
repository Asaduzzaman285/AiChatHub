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

        $frontendUrl = rtrim((string) config('services.frontend_url'), '/');
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
            'metadata'        => $metadata,
        ]);

        $frontendUrl = rtrim((string) config('services.frontend_url'), '/');
        $returnType  = $type === 'wallet_topup' ? 'topup' : 'subscription';
        $callbackUrl = "{$frontendUrl}/billing/checkout-callback?type={$returnType}";
        $fullMetadata = array_merge($metadata, ['transaction_id' => $transaction->id, 'user_id' => $userId]);

        $result = $bkash->createCheckoutSession($amountUsd, $description, $callbackUrl, $fullMetadata);

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
            'metadata'          => array_merge($fullMetadata, ['amount_bdt' => $result['amount_bdt']]),
        ]);

        return ['transaction' => $transaction, 'checkout_url' => $result['bkash_url'], 'error' => null];
    }
}
