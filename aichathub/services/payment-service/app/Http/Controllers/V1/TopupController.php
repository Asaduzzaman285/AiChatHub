<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\CreatesCheckoutSessions;
use App\Models\Transaction;
use App\Services\BkashGateway;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopupController extends Controller
{
    use CreatesCheckoutSessions;

    /**
     * POST /topup — creates a Checkout Session for a wallet top-up, via
     * Stripe (card) or bKash. The wallet is only credited once the payment is
     * verified, either by the frontend's return-page call to
     * GET /checkout/{id}/verify or (Stripe only) by its webhook — see
     * CheckoutCompletionService.
     */
    public function initiate(Request $request, StripeGateway $stripe, BkashGateway $bkash): JsonResponse
    {
        $data = $request->validate([
            // The real floor is enforced per-gateway below (bKash's own ৳20
            // native minimum is far below $1) — this just rejects zero/negative.
            'amount'   => 'required|numeric|min:0.01',
            // Stripe-only — it can charge a card in whatever currency is given.
            // bKash always settles in BDT (converted from USD internally by
            // BkashGateway), so this has no effect and is rejected below if
            // the caller passes anything but USD, rather than silently ignored.
            'currency' => 'nullable|string|size:3',
            'gateway'  => 'nullable|in:stripe,bkash',
        ]);

        $userId  = $this->authUserId($request);
        $gateway = $data['gateway'] ?? 'stripe';
        $amount  = (float) $data['amount'];
        $origin  = $request->header('Origin');

        // No-op on any origin but staging.alveta.ai — see StripeGateway::useSandboxIfOrigin().
        $stripe->useSandboxIfOrigin($origin);
        $bkash->useSandboxIfOrigin($origin);

        if ($gateway === 'bkash' && strtoupper($data['currency'] ?? 'USD') !== 'USD') {
            return response()->json(['error' => 'bKash top-ups must be specified in USD (converted to BDT automatically).'], 422);
        }

        // Card top-ups keep a flat $1 USD floor. bKash's floor is a native ৳20
        // BDT figure instead of a USD amount pushed through the currency
        // policy — a $1 floor converts to ~৳202 at the live rate, which was
        // blocking legitimate small BDT top-ups (confirmed live: a genuine
        // ৳1 top-up attempt was rejected with "Minimum top-up is BDT 202.13",
        // a policy problem, not a math bug). $amount here is always USD (see
        // beginBkashCheckout's own $amountUsd param), so bKash's ৳20 floor has
        // to be converted the other way, via the same live rate BkashGateway
        // itself uses to go from USD to BDT.
        if ($gateway === 'bkash') {
            $minBdt = 20.0;
            $minUsd = $minBdt / $bkash->currentBdtRate();

            if ($amount < $minUsd) {
                return response()->json(['error' => 'Minimum top-up is ৳20 when paying with bKash.'], 422);
            }
        } elseif ($amount < 1.0) {
            return response()->json(['error' => 'Minimum top-up is $1.00 when paying by card.'], 422);
        }

        $result = $gateway === 'bkash'
            ? $this->beginBkashCheckout(
                $bkash,
                $userId,
                'wallet_topup',
                $amount,
                'Alveta.ai wallet top-up',
                ['type' => 'wallet_topup'],
                null,
                $origin,
            )
            : $this->beginCheckout(
                $stripe,
                $userId,
                'wallet_topup',
                $amount,
                strtoupper($data['currency'] ?? 'USD'),
                'Alveta.ai wallet top-up',
                ['type' => 'wallet_topup'],
                $origin,
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
