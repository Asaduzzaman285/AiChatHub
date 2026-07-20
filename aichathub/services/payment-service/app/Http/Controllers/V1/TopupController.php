<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\InternalServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class TopupController extends Controller
{
    public function __construct(private InternalServiceClient $internal) {}

    /**
     * POST /topup — creates + confirms a Stripe PaymentIntent for a wallet top-up.
     * If Stripe confirms synchronously (common in test mode), the wallet is
     * credited immediately. Otherwise the transaction stays "pending" and the
     * Stripe webhook (payment_intent.succeeded → ProcessStripeWebhookJob)
     * credits it once confirmation completes — that job also acts as a retry
     * path if the synchronous credit call below fails.
     */
    public function initiate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount'               => 'required|numeric|min:1',
            'currency'             => 'nullable|string|size:3',
            'payment_method_token' => 'required|string',
        ]);

        $userId         = $this->authUserId($request);
        $currency       = strtoupper($data['currency'] ?? 'USD');
        $amount         = (float) $data['amount'];
        $idempotencyKey = (string) Str::uuid();

        $transaction = Transaction::create([
            'user_id'         => $userId,
            'type'            => 'wallet_topup',
            'status'          => 'pending',
            'amount'          => $amount,
            'currency'        => $currency,
            'gateway'         => 'stripe',
            'idempotency_key' => $idempotencyKey,
            'description'     => 'Wallet top-up',
        ]);

        try {
            $client = new StripeClient(config('services.stripe.secret'));

            $intent = $client->paymentIntents->create([
                'amount'                    => (int) round($amount * 100),
                'currency'                  => strtolower($currency),
                'payment_method'            => $data['payment_method_token'],
                'confirm'                   => true,
                'description'               => 'AI ChatHub wallet top-up',
                'metadata'                  => ['user_id' => $userId, 'transaction_id' => $transaction->id],
                'automatic_payment_methods' => ['enabled' => true, 'allow_redirects' => 'never'],
            ], ['idempotency_key' => $idempotencyKey]);

            $transaction->update(['gateway_reference' => $intent->id]);
        } catch (ApiErrorException $e) {
            $transaction->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'failed_at'     => now(),
            ]);

            return response()->json(['error' => $e->getMessage()], 422);
        }

        $walletCredited = false;

        if ($intent->status === 'succeeded') {
            $walletCredited = $this->internal->creditWallet($userId, $amount, 'Wallet top-up', $transaction->id);

            if ($walletCredited) {
                $transaction->update(['status' => 'completed', 'completed_at' => now()]);
                $this->internal->createReceipt($userId, $amount, $currency, $transaction->id);
                $this->internal->sendReceiptEmail($userId, $amount, $currency, 'Wallet top-up', "receipt:topup:{$transaction->id}");
            }
            // If crediting failed, leave status "pending" — the webhook will retry.
        }

        return response()->json([
            'transaction_id'  => $transaction->id,
            'status'          => $transaction->fresh()->status,
            'client_secret'   => $intent->client_secret,
            'wallet_credited' => $walletCredited,
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
