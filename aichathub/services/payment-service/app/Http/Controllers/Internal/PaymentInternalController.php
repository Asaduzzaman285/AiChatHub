<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PaymentInternalController extends Controller
{
    public function __construct(private StripeGateway $stripe) {}

    /**
     * POST /internal/payments/charge
     * Called by Subscription Service for purchases and renewals.
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

    private function publishEvent(string $event, array $payload): void
    {
        Redis::publish('payment-events', json_encode([
            'event'     => $event,
            'payload'   => $payload,
            'timestamp' => now()->toIso8601String(),
        ]));
    }
}
