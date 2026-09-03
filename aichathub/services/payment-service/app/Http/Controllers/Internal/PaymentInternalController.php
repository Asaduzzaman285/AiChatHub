<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Concerns\CreatesCheckoutSessions;
use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Services\BkashGateway;
use App\Services\RefundService;
use App\Services\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class PaymentInternalController extends Controller
{
    use CreatesCheckoutSessions;

    public function __construct(private StripeGateway $stripe, private BkashGateway $bkash, private RefundService $refunds) {}

    /**
     * POST /internal/payments/checkout
     * Called by Subscription Service to start a Checkout-Session-funded package
     * purchase (card or bKash). Unlike charge() below, this never activates
     * anything itself — the caller only gets a checkout_url back; activation
     * happens later via CheckoutCompletionService once the payment is verified.
     */
    public function createCheckoutSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id'      => 'required|uuid',
            'amount'       => 'required|numeric|min:0.01',
            // Stripe-only, same as TopupController — bKash always settles in
            // BDT (converted from USD internally), so it's rejected below
            // rather than silently ignored if the caller passes anything else.
            'currency'     => 'required|string|in:USD,BDT',
            'description'  => 'required|string',
            'package_slug' => 'required|string',
            'gateway'      => 'nullable|in:stripe,bkash',
            // Distinguishes a fresh purchase from an upgrade on an existing
            // subscription — CheckoutCompletionService routes each to a
            // different subscription-service internal endpoint on completion.
            'type'         => 'nullable|in:subscription_purchase,subscription_upgrade',
            // The end user's actual browser Origin, forwarded through by
            // subscription-service (see SubscriptionController) — this is a
            // server-to-server call, so the real Origin header is otherwise lost at
            // this hop. Used for both sandbox key selection and the checkout
            // redirect target; absent entirely for any caller that doesn't send it,
            // which just falls through to today's existing (live, FRONTEND_URL)
            // behavior.
            'origin'       => 'nullable|string',
        ]);

        $gateway = $data['gateway'] ?? 'stripe';
        $type    = $data['type'] ?? 'subscription_purchase';

        $this->stripe->useSandboxIfOrigin($data['origin'] ?? null);

        if ($gateway === 'bkash' && $data['currency'] !== 'USD') {
            return response()->json(['error' => 'bKash purchases must be specified in USD (converted to BDT automatically).'], 422);
        }

        $result = $gateway === 'bkash'
            ? $this->beginBkashCheckout(
                $this->bkash,
                $data['user_id'],
                $type,
                (float) $data['amount'],
                $data['description'],
                ['package_slug' => $data['package_slug']],
            )
            : $this->beginCheckout(
                $this->stripe,
                $data['user_id'],
                $type,
                (float) $data['amount'],
                $data['currency'],
                $data['description'],
                ['package_slug' => $data['package_slug']],
                $data['origin'] ?? null,
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

    /**
     * GET /internal/payment-methods/{userId}/default
     * Called by Subscription Service's renewal job — a background charge has no
     * browser to redirect through, so it needs a previously-saved card, not Checkout.
     */
    public function defaultPaymentMethod(string $userId): JsonResponse
    {
        $method = PaymentMethod::where('user_id', $userId)
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();

        if (! $method) {
            return response()->json(['payment_method_token' => null]);
        }

        return response()->json(['payment_method_token' => $method->token]);
    }

    /** GET /internal/payment-methods/{id} — used by wallet-service's auto-debit, when a
     * user has picked a specific saved card rather than "use my default". */
    public function byId(string $id): JsonResponse
    {
        $method = PaymentMethod::where('id', $id)->where('is_active', true)->first();

        if (! $method) {
            return response()->json(['payment_method_token' => null]);
        }

        return response()->json(['payment_method_token' => $method->token]);
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
        $result      = $this->refunds->refund($transaction, isset($data['amount']) ? (float) $data['amount'] : null);

        if (! $result['success']) {
            return response()->json(['error' => $result['error']], 422);
        }

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
