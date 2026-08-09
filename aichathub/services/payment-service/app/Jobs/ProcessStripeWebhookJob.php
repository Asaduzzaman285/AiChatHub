<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\WebhookEvent;
use App\Services\CheckoutCompletionService;
use App\Services\StripeGateway;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Authoritative backup to the frontend's verify-on-return call (CheckoutController::verify) —
 * both funnel through CheckoutCompletionService, which is idempotent per transaction, so it
 * doesn't matter which of the two lands first.
 */
class ProcessStripeWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly string $stripeEventId) {}

    public function handle(CheckoutCompletionService $completion, StripeGateway $stripe): void
    {
        $webhookEvent = WebhookEvent::where('gateway', 'stripe')
            ->where('gateway_reference', $this->stripeEventId)
            ->first();

        if (! $webhookEvent || $webhookEvent->status === 'processed') {
            return;
        }

        try {
            match ($webhookEvent->event_type) {
                'checkout.session.completed' => $this->handleCompleted($webhookEvent, $completion),
                'checkout.session.expired'   => $this->handleExpired($webhookEvent, $completion),
                'charge.dispute.created'     => $this->handleDisputeCreated($webhookEvent, $stripe),
                'charge.dispute.closed'      => $this->handleDisputeClosed($webhookEvent, $stripe),
                default => null,
            };

            $webhookEvent->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing failed: '.$e->getMessage(), ['stripe_event_id' => $this->stripeEventId]);
            $webhookEvent->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'retry_count'   => $webhookEvent->retry_count + 1,
            ]);
            throw $e;
        }
    }

    private function handleCompleted(WebhookEvent $webhookEvent, CheckoutCompletionService $completion): void
    {
        $transaction = $this->findTransaction($webhookEvent);
        if (! $transaction) {
            return;
        }

        $completion->complete($transaction);
    }

    private function handleExpired(WebhookEvent $webhookEvent, CheckoutCompletionService $completion): void
    {
        $transaction = $this->findTransaction($webhookEvent);
        if (! $transaction) {
            return;
        }

        $completion->cancel($transaction);
    }

    private function findTransaction(WebhookEvent $webhookEvent): ?Transaction
    {
        $sessionId = data_get($webhookEvent->payload, 'data.object.id');
        if (! $sessionId) {
            return null;
        }

        $transaction = Transaction::where('gateway', 'stripe')
            ->where('gateway_reference', $sessionId)
            ->first();

        if ($transaction) {
            $webhookEvent->update(['transaction_id' => $transaction->id]);
        }

        return $transaction;
    }

    /**
     * A dispute payload carries a payment_intent/charge id, not the checkout session
     * id findTransaction() above matches on — two different Transaction shapes can
     * be behind that id, so this tries both:
     *   1. Saved-card charges (PaymentInternalController::charge() — subscription
     *      renewals, auto-debit) store the PaymentIntent id directly as
     *      gateway_reference, set after the charge succeeds — a direct match.
     *   2. Checkout Session charges (topups, initial purchase) store the *session*
     *      id as gateway_reference instead; those never match directly, so this
     *      falls back to retrieving the PaymentIntent from Stripe and reading
     *      transaction_id back out of its metadata (set at session creation via
     *      payment_intent_data.metadata in createCheckoutSession()).
     */
    private function findTransactionByPaymentIntent(WebhookEvent $webhookEvent, StripeGateway $stripe): ?Transaction
    {
        $paymentIntentId = data_get($webhookEvent->payload, 'data.object.payment_intent');
        if (! $paymentIntentId) {
            return null;
        }

        $transaction = Transaction::where('gateway', 'stripe')
            ->where('gateway_reference', $paymentIntentId)
            ->first();

        if (! $transaction) {
            try {
                $intent = $stripe->retrievePaymentIntent($paymentIntentId);
            } catch (ApiErrorException $e) {
                Log::error('Dispute webhook: could not retrieve PaymentIntent.', [
                    'payment_intent_id' => $paymentIntentId,
                    'error'             => $e->getMessage(),
                ]);
                return null;
            }

            $transactionId = $intent->metadata['transaction_id'] ?? null;
            $transaction   = $transactionId ? Transaction::find($transactionId) : null;
        }

        if ($transaction) {
            $webhookEvent->update(['transaction_id' => $transaction->id]);
        }

        return $transaction;
    }

    /**
     * A real chargeback landing on this transaction. Previously unhandled entirely —
     * the transaction just sat at 'completed' forever with no record anything had
     * gone wrong. Logged at 'critical' specifically so it stands out in log
     * aggregation / alerting (Sentry) once that's wired up, rather than blending in
     * with routine warning/error noise.
     */
    private function handleDisputeCreated(WebhookEvent $webhookEvent, StripeGateway $stripe): void
    {
        $transaction = $this->findTransactionByPaymentIntent($webhookEvent, $stripe);
        if (! $transaction) {
            Log::critical('Stripe dispute created for an unrecognized transaction.', [
                'payload' => $webhookEvent->payload,
            ]);
            return;
        }

        $dispute = data_get($webhookEvent->payload, 'data.object', []);

        $transaction->update([
            'status'      => 'disputed',
            'disputed_at' => now(),
            'metadata'    => array_merge($transaction->metadata ?? [], [
                'dispute_id'     => $dispute['id'] ?? null,
                'dispute_reason' => $dispute['reason'] ?? null,
                'dispute_amount' => isset($dispute['amount']) ? $dispute['amount'] / 100 : null,
            ]),
        ]);

        Log::critical('Stripe dispute created.', [
            'transaction_id' => $transaction->id,
            'user_id'        => $transaction->user_id,
            'dispute_id'     => $dispute['id'] ?? null,
            'reason'         => $dispute['reason'] ?? null,
        ]);
    }

    /**
     * Dispute resolved — 'won' means Stripe/the bank sided with us, revert to
     * 'completed'. 'lost' means the funds actually left, treated the same as a
     * refund from this app's point of view. Anything else (warning_closed, etc.)
     * just gets logged, not silently left at 'disputed' forever.
     */
    private function handleDisputeClosed(WebhookEvent $webhookEvent, StripeGateway $stripe): void
    {
        $transaction = $this->findTransactionByPaymentIntent($webhookEvent, $stripe);
        if (! $transaction) {
            return;
        }

        $disputeStatus = data_get($webhookEvent->payload, 'data.object.status');

        match ($disputeStatus) {
            'won'  => $transaction->update(['status' => 'completed']),
            'lost' => $transaction->update(['status' => 'refunded', 'refunded_at' => now()]),
            default => null,
        };

        Log::info('Stripe dispute closed.', [
            'transaction_id' => $transaction->id,
            'dispute_status' => $disputeStatus,
        ]);
    }
}
