<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single place a Checkout Session actually gets turned into a credited
 * wallet or an activated subscription. Called from two independent triggers —
 * the frontend's verify-on-return call and the Stripe webhook — so every step
 * is guarded by the transaction's current status rather than assuming it's
 * the only caller that will ever run.
 */
class CheckoutCompletionService
{
    public function __construct(private InternalServiceClient $internal) {}

    public function complete(Transaction $transaction): void
    {
        if ($transaction->status === 'completed') {
            return;
        }

        $transaction->update(['status' => 'processing']);

        $credited = match ($transaction->type) {
            'wallet_topup'          => $this->completeTopup($transaction),
            'subscription_purchase' => $this->completeSubscription($transaction),
            default                 => false,
        };

        if (! $credited) {
            // Leave at "processing" — safe to retry, both the webhook redelivery
            // and the user reloading the return page call complete() again, and
            // the completed-status guard above makes that idempotent once it works.
            return;
        }

        $transaction->update(['status' => 'completed', 'completed_at' => now()]);
    }

    public function cancel(Transaction $transaction): void
    {
        if (in_array($transaction->status, ['completed', 'cancelled'], true)) {
            return;
        }

        $transaction->update(['status' => 'cancelled']);
    }

    private function completeTopup(Transaction $transaction): bool
    {
        $credited = $this->internal->creditWallet(
            $transaction->user_id,
            (float) $transaction->amount,
            'Wallet top-up',
            $transaction->id,
        );

        if ($credited) {
            $this->internal->createReceipt($transaction->user_id, (float) $transaction->amount, $transaction->currency, $transaction->id);
            $this->internal->sendReceiptEmail($transaction->user_id, (float) $transaction->amount, $transaction->currency, 'Wallet top-up', "receipt:topup:{$transaction->id}");
        }

        return $credited;
    }

    private function completeSubscription(Transaction $transaction): bool
    {
        $packageSlug = $transaction->metadata['package_slug'] ?? null;
        if (! $packageSlug) {
            Log::error('Checkout completion missing package_slug in transaction metadata.', ['transaction_id' => $transaction->id]);
            return false;
        }

        $subscriptionUrl = rtrim((string) config('services.subscription_url'), '/');
        $internalKey     = config('services.internal_key');

        if (! $subscriptionUrl || ! $internalKey) {
            Log::error('Subscription activation skipped — subscription_url/internal_key not configured.', ['transaction_id' => $transaction->id]);
            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-Internal-Service-Key' => $internalKey,
                'Accept'                 => 'application/json',
            ])->timeout(20)->post("{$subscriptionUrl}/api/internal/subscriptions/activate", [
                'user_id'        => $transaction->user_id,
                'package_slug'   => $packageSlug,
                'transaction_id' => $transaction->id,
                'currency'       => $transaction->currency,
            ]);

            if (! $response->successful()) {
                return false;
            }

            $this->internal->createReceipt($transaction->user_id, (float) $transaction->amount, $transaction->currency, $transaction->id, 'subscription_purchase');

            return true;
        } catch (\Exception $e) {
            Log::error('Subscription activation call failed: '.$e->getMessage(), ['transaction_id' => $transaction->id]);
            return false;
        }
    }
}
