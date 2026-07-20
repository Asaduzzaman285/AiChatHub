<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\WebhookEvent;
use App\Services\InternalServiceClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessStripeWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly string $stripeEventId) {}

    public function handle(InternalServiceClient $internal): void
    {
        $webhookEvent = WebhookEvent::where('gateway', 'stripe')
            ->where('gateway_reference', $this->stripeEventId)
            ->first();

        if (! $webhookEvent || $webhookEvent->status === 'processed') {
            return;
        }

        try {
            match ($webhookEvent->event_type) {
                'payment_intent.succeeded' => $this->handleSucceeded($webhookEvent, $internal),
                'payment_intent.payment_failed' => $this->handleFailed($webhookEvent),
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

    private function handleSucceeded(WebhookEvent $webhookEvent, InternalServiceClient $internal): void
    {
        $paymentIntentId = data_get($webhookEvent->payload, 'data.object.id');
        if (! $paymentIntentId) {
            return;
        }

        $transaction = Transaction::where('gateway', 'stripe')
            ->where('gateway_reference', $paymentIntentId)
            ->first();

        // Nothing to reconcile, or TopupController already credited it synchronously.
        if (! $transaction || $transaction->status === 'completed') {
            return;
        }

        $webhookEvent->update(['transaction_id' => $transaction->id]);

        if ($transaction->type !== 'wallet_topup') {
            return;
        }

        $credited = $internal->creditWallet(
            $transaction->user_id,
            (float) $transaction->amount,
            'Wallet top-up',
            $transaction->id,
        );

        if ($credited) {
            $transaction->update(['status' => 'completed', 'completed_at' => now()]);
            $internal->createReceipt($transaction->user_id, (float) $transaction->amount, $transaction->currency, $transaction->id);
            $internal->sendReceiptEmail($transaction->user_id, (float) $transaction->amount, $transaction->currency, 'Wallet top-up', "receipt:topup:{$transaction->id}");
        }
        // If crediting failed, leave the transaction pending — retrying this job
        // (tries=3) will attempt the credit again before giving up.
    }

    private function handleFailed(WebhookEvent $webhookEvent): void
    {
        $paymentIntentId = data_get($webhookEvent->payload, 'data.object.id');
        if (! $paymentIntentId) {
            return;
        }

        $transaction = Transaction::where('gateway', 'stripe')
            ->where('gateway_reference', $paymentIntentId)
            ->first();

        if (! $transaction || $transaction->status === 'completed') {
            return;
        }

        $webhookEvent->update(['transaction_id' => $transaction->id]);

        $transaction->update([
            'status'        => 'failed',
            'error_message' => data_get($webhookEvent->payload, 'data.object.last_payment_error.message', 'Payment failed'),
            'failed_at'     => now(),
        ]);
    }
}
