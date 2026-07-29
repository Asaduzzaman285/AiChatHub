<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Redis;

/**
 * Extracted from Internal\PaymentInternalController@refund (built 2026-07-23
 * for subscription/topup reversal) so the new admin-facing refund endpoint
 * (Finance Administrator's "process refunds" responsibility) reuses the exact
 * same Stripe/bKash branching instead of duplicating it.
 */
class RefundService
{
    public function __construct(private StripeGateway $stripe, private BkashGateway $bkash) {}

    /** @return array{success: bool, error?: string, refund_id?: ?string, amount?: float} */
    public function refund(Transaction $transaction, ?float $amount = null): array
    {
        if ($transaction->status !== 'completed') {
            return ['success' => false, 'error' => 'transaction_not_completed'];
        }

        if (! $transaction->gateway_reference) {
            return ['success' => false, 'error' => 'no_gateway_reference'];
        }

        $amount = $amount ?? (float) $transaction->amount;

        if ($transaction->gateway === 'bkash') {
            $trxId = $transaction->metadata['trx_id'] ?? null;
            if (! $trxId) {
                return ['success' => false, 'error' => 'no_bkash_trx_id'];
            }
            $amountBdt = $transaction->metadata['amount_bdt'] ?? $this->bkash->usdToBdt($amount);
            $refund    = $this->bkash->refund($transaction->gateway_reference, $trxId, $amountBdt, 'Refund requested');
            $result    = ['success' => $refund['success'], 'refund_id' => $refund['refund_trx_id'] ?? null, 'error' => $refund['error'] ?? null];
        } else {
            $result = $this->stripe->refund($transaction->gateway_reference, $amount);
        }

        if (! $result['success']) {
            return ['success' => false, 'error' => $result['error']];
        }

        $transaction->update(['status' => 'refunded', 'refunded_at' => now()]);

        Redis::publish('payment-events', json_encode([
            'event'     => 'payment.refunded',
            'payload'   => ['transaction_id' => $transaction->id, 'user_id' => $transaction->user_id, 'amount' => $amount],
            'timestamp' => now()->toIso8601String(),
        ]));

        return ['success' => true, 'refund_id' => $result['refund_id'] ?? null, 'amount' => $amount];
    }
}
