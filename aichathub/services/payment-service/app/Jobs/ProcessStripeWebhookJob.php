<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\WebhookEvent;
use App\Services\CheckoutCompletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    public function handle(CheckoutCompletionService $completion): void
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
}
