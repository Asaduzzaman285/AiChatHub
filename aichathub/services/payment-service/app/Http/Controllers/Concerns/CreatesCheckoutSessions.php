<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Transaction;
use App\Services\StripeGateway;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;

/**
 * Shared by TopupController (user-facing) and PaymentInternalController
 * (called by Subscription Service) — both need the same "create a pending
 * Transaction, then create the matching Stripe Checkout Session" sequence.
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
}
