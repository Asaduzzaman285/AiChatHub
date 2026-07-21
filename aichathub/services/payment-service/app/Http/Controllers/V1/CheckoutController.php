<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\CheckoutCompletionService;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /**
     * GET /checkout/{sessionId}/verify
     * Called by the frontend the moment it lands back from Stripe's hosted
     * page — asks Stripe directly whether the session was actually paid and,
     * if so, completes it right away. The webhook (checkout.session.completed)
     * does the same thing independently as an authoritative backup; both go
     * through CheckoutCompletionService, which is idempotent per transaction.
     */
    public function verify(Request $request, string $sessionId, StripeGateway $stripe, CheckoutCompletionService $completion): JsonResponse
    {
        $transaction = Transaction::where('gateway_reference', $sessionId)
            ->where('user_id', $this->authUserId($request))
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Checkout session not found.', 'error' => 'not_found'], 404);
        }

        if (! in_array($transaction->status, ['completed', 'cancelled', 'failed'], true)) {
            $session = $stripe->retrieveCheckoutSession($sessionId);

            if ($session->payment_status === 'paid') {
                $completion->complete($transaction);
            } elseif ($session->status === 'expired') {
                $completion->cancel($transaction);
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
