<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\CreatesCheckoutSessions;
use App\Models\Transaction;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopupController extends Controller
{
    use CreatesCheckoutSessions;

    /**
     * POST /topup — creates a Stripe Checkout Session for a wallet top-up.
     * The wallet is only credited once the payment is verified, either by the
     * frontend's return-page call to GET /checkout/{id}/verify or by the Stripe
     * webhook (checkout.session.completed) — see CheckoutCompletionService.
     */
    public function initiate(Request $request, StripeGateway $stripe): JsonResponse
    {
        $data = $request->validate([
            'amount'   => 'required|numeric|min:1',
            'currency' => 'nullable|string|size:3',
        ]);

        $userId   = $this->authUserId($request);
        $currency = strtoupper($data['currency'] ?? 'USD');
        $amount   = (float) $data['amount'];

        $result = $this->beginCheckout(
            $stripe,
            $userId,
            'wallet_topup',
            $amount,
            $currency,
            'AI ChatHub wallet top-up',
            ['type' => 'wallet_topup'],
        );

        if ($result['error']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json([
            'transaction_id' => $result['transaction']->id,
            'status'         => $result['transaction']->status,
            'checkout_url'   => $result['checkout_url'],
        ], 201);
    }

    /** GET /topup/{id}/status */
    public function status(Request $request, string $id): JsonResponse
    {
        $transaction = Transaction::where('id', $id)
            ->where('user_id', $this->authUserId($request))
            ->where('type', 'wallet_topup')
            ->first();

        if (! $transaction) {
            return response()->json(['message' => 'Transaction not found.', 'error' => 'not_found'], 404);
        }

        return response()->json([
            'transaction_id' => $transaction->id,
            'status'         => $transaction->status,
            'amount'         => (float) $transaction->amount,
            'currency'       => $transaction->currency,
        ]);
    }
}
