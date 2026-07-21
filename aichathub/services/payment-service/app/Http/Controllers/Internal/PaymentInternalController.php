<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Concerns\CreatesCheckoutSessions;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PaymentInternalController extends Controller
{
    use CreatesCheckoutSessions;

    public function __construct(private StripeGateway $stripe) {}

    /**
     * POST /internal/payments/checkout
     * Called by Subscription Service to start a Checkout-Session-funded package
     * purchase. Unlike charge() below, this never activates anything itself —
     * the caller only gets a checkout_url back; activation happens later via
     * CheckoutCompletionService once the payment is verified.
     */
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'      => 'required|uuid',
            'amount'       => 'required|numeric|min:0.01',
            'currency'     => 'required|string|in:USD,BDT',
            'description'  => 'required|string',
            'package_slug' => 'required|string',
        ]);

        $result = $this->beginCheckout(
            $this->stripe,
            $data['user_id'],
            'subscription_purchase',
            (float) $data['amount'],
            $data['currency'],
            $data['description'],
            ['package_slug' => $data['package_slug']],
        );

        if ($result['error']) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json([
            'transaction_id' => $result['transaction']->id,
            'checkout_url'   => $result['checkout_url'],
        ], 201);
    }

    /**
     * POST /internal/payments/charge
     * Legacy direct-charge path (synchronous PaymentIntent, caller-supplied
     * payment method token) — superseded by createCheckoutSession() above for
     * package purchases, kept as-is for potential future saved-card use.
     */
    public function charge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'              => 'required|uuid',
            'amount'               => 'required|numeric|min:0.01',
            'currency'             => 'required|string|in:USD,BDT',
            'payment_method_token' => 'required|string',
            'idempotency_key'      => 'required|string|max:255',
            'description'          => 'required|string',
        ]);

        // Idempotency: return existing completed transaction if key already used
        $existing = Transaction::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing && $existing->status === 'completed') {
            return response()->json(['transaction_id' => $existing->id, 'status' => 'completed']);
        }

        // Create pending transaction
        $transaction = Transaction::create([
            'user_id'         => $data['user_id'],
            'type'            => 'subscription_purchase',
            'status'          => 'pending',
            'amount'          => $data['amount'],
            'currency'        => $data['currency'],
            'gateway'         => 'stripe',
            'idempotency_key' => $data['idempotency_key'],
            'description'     => $data['description'],
        ]);

        // Call Stripe
        $result = $this->stripe->charge(
            $data['user_id'],
            $data['payment_method_token'],
            (float) $data['amount'],
            $data['currency'],
            $data['idempotency_key'],
            $data['description']
        );

        if ($result['success']) {
            $transaction->update([
                'status'            => 'completed',
                'gateway_reference' => $result['gateway_reference'],
                'completed_at'      => now(),
            ]);

            $this->publishEvent('payment.succeeded', [
                'transaction_id' => $transaction->id,
                'user_id'        => $data['user_id'],
                'amount'         => $data['amount'],
                'currency'       => $data['currency'],
                'gateway'        => 'stripe',
            ]);

            return response()->json([
                'transaction_id'    => $transaction->id,
                'status'            => 'completed',
                'gateway_reference' => $result['gateway_reference'],
            ]);
        }

        $transaction->update([
            'status'        => 'failed',
            'error_message' => $result['error'],
            'failed_at'     => now(),
        ]);

        $this->publishEvent('payment.failed', [
            'transaction_id' => $transaction->id,
            'user_id'        => $data['user_id'],
            'amount'         => $data['amount'],
            'error'          => $result['error'],
        ]);

        return response()->json([
            'transaction_id' => $transaction->id,
            'status'         => 'failed',
            'error'          => $result['error'],
        ], 422);
    }

    /** GET /internal/payments/{id} */
    public function show(string $id): JsonResponse
    {
        $txn = Transaction::findOrFail($id);
        return response()->json($txn);
    }

    /** POST /internal/payments/refund — called when a subscription/topup needs to be reversed */
    public function refund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_id' => 'required|uuid',
            'amount'         => 'nullable|numeric|min:0.01',
        ]);

        $transaction = Transaction::findOrFail($data['transaction_id']);

        if ($transaction->status !== 'completed') {
            return response()->json(['error' => 'transaction_not_completed'], 422);
        }

        if (! $transaction->gateway_reference) {
            return response()->json(['error' => 'no_gateway_reference'], 422);
        }

        $amount = (float) ($data['amount'] ?? $transaction->amount);
        $result = $this->stripe->refund($transaction->gateway_reference, $amount);

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

        $transaction->update([
            'status'       => 'refunded',
            'refunded_at'  => now(),
        ]);

        $this->publishEvent('payment.refunded', [
            'transaction_id' => $transaction->id,
            'user_id'        => $transaction->user_id,
            'amount'         => $amount,
        ]);

        return response()->json([
            'transaction_id' => $transaction->id,
            'status'         => 'refunded',
            'refund_id'      => $result['refund_id'],
        ]);
    }

    private function publishEvent(string $event, array $payload): void
    {
        Redis::publish('payment-events', json_encode([
            'event'     => $event,
            'payload'   => $payload,
            'timestamp' => now()->toIso8601String(),
        ]));
    }
}
