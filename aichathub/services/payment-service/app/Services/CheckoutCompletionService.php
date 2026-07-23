<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The single place a Checkout Session actually gets turned into a credited
 * wallet or an activated subscription. Called from two independent triggers —
 * the frontend's verify-on-return call and the Stripe webhook — plus, in
 * practice, potentially several browser tabs each independently polling
 * verify-on-return for the same session. The completed-status guard alone
 * only protects against *sequential* re-entry; two calls landing at nearly
 * the same instant can both pass it before either has updated the row. The
 * claim step below closes that gap with a real row lock.
 */
class CheckoutCompletionService
{
    public function __construct(private InternalServiceClient $internal) {}

    public function complete(Transaction $transaction): void
    {
        // Claim the row under lock — only one concurrent caller can win this,
        // everyone else sees 'processing' (still claimed) or 'completed' and
        // returns immediately instead of racing into completeTopup()/
        // completeSubscription() (and double-creating a receipt) together.
        $claimed = DB::transaction(function () use ($transaction) {
            $locked = Transaction::where('id', $transaction->id)->lockForUpdate()->first();

            if (! $locked || in_array($locked->status, ['completed', 'processing'], true)) {
                return null;
            }

            $locked->update(['status' => 'processing']);

            return $locked;
        });

        if (! $claimed) {
            return;
        }

        $credited = match ($claimed->type) {
            'wallet_topup'          => $this->completeTopup($claimed),
            'subscription_purchase' => $this->completeSubscription($claimed),
            default                 => false,
        };

        if (! $credited) {
            // Revert the claim, not leave it at 'processing' — 'processing' must
            // only ever mean "actively in flight right now". Reverting to
            // 'pending' lets a genuine retry (webhook redelivery, the user
            // reloading the return page) claim and try again; leaving it at
            // 'processing' would permanently block every future retry, since
            // the claim check above treats 'processing' as "someone else has
            // this."
            $claimed->update(['status' => 'pending']);
            return;
        }

        $claimed->update(['status' => 'completed', 'completed_at' => now()]);
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
