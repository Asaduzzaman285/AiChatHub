<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\BkashGateway;
use App\Services\CheckoutCompletionService;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /**
     * GET /checkout/{sessionId}/verify
     * Called by the frontend the moment it lands back from the gateway's
     * hosted page — sessionId is either a Stripe Checkout Session ID or a
     * bKash paymentID, whichever this transaction's gateway_reference holds.
     *
     * Stripe: asks Stripe directly whether the session was actually paid;
     * its webhook (checkout.session.completed) does the same thing
     * independently as an authoritative backup.
     *
     * bKash: this package has no server-to-server webhook, so calling
     * executePayment() here is the ONLY completion path — if the browser
     * never returns to this page, the transaction stays pending with no
     * automatic reconciliation (see HANDOFF's Known Issues). Unlike Stripe's
     * retrieveCheckoutSession (read-only), executePayment() is a one-time
     * mutating call bKash rejects on a second attempt — so once a `trx_id`
     * has been recorded, any retry (e.g. CheckoutCompletionService failed
     * after a successful execute) uses the read-only queryPayment() instead,
     * never re-executing.
     *
     * Both branches funnel through CheckoutCompletionService, which is
     * idempotent per transaction — and both are naturally guarded against a
     * second gateway call by the `status` check below, since a completed/
     * cancelled/failed transaction skips straight to returning its state.
     */
    public function verify(
        Request $request,
        string $sessionId,
        StripeGateway $stripe,
        BkashGateway $bkash,
        CheckoutCompletionService $completion
    ): JsonResponse {
        $transaction = Transaction::where('gateway_reference', $sessionId)
            ->where('user_id', $this->authUserId($request))
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Checkout session not found.', 'error' => 'not_found'], 404);
        }

        if (! in_array($transaction->status, ['completed', 'cancelled', 'failed'], true)) {
            if ($transaction->gateway === 'bkash') {
                $alreadyExecuted = ! empty($transaction->metadata['trx_id'] ?? null);
                $result = $alreadyExecuted ? $bkash->queryPayment($sessionId) : $bkash->executePayment($sessionId);

                if (! $alreadyExecuted && ($result['trx_id'] ?? null)) {
                    $transaction->update(['metadata' => array_merge($transaction->metadata ?? [], ['trx_id' => $result['trx_id']])]);
                }

                if ($result['success']) {
                    $completion->complete($transaction);
                } elseif (! $alreadyExecuted && $result['error']) {
                    // Only the first (execute) attempt's failure is a trustworthy
                    // cancellation signal — a query-based retry failing just means
                    // "not completed yet," not "this was cancelled."
                    $completion->cancel($transaction);
                }
            } else {
                $session = $stripe->retrieveCheckoutSession($sessionId);

                if ($session->payment_status === 'paid') {
                    $completion->complete($transaction);
                } elseif ($session->status === 'expired') {
                    $completion->cancel($transaction);
                }
            }
        }

        $transaction->refresh();

        return response()->json([
            'transaction_id' => $transaction->id,
            'type'           => $transaction->type,
            'status'         => $transaction->status,
            'amount'         => (float) $transaction->amount,
            'currency'       => $transaction->currency,
        ]);
    }
}
