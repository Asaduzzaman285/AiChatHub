<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\BkashGateway;
use App\Services\CheckoutCompletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Resolves one stuck-pending bKash transaction via the read-only queryPayment()
 * (safe to call repeatedly, unlike executePayment()). No self-rescheduling
 * needed here — ReconcileBkashCommand's own 15-minute schedule is the retry
 * cadence; this job just needs a hard age ceiling so nothing sweeps forever.
 */
class ReconcileBkashPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $transactionId) {}

    public function handle(BkashGateway $bkash, CheckoutCompletionService $completion): void
    {
        $transaction = Transaction::find($this->transactionId);

        // Already resolved — verify-on-return (or a previous sweep) beat us to it.
        if (! $transaction || $transaction->status !== 'pending') {
            return;
        }

        $result = $bkash->queryPayment($transaction->gateway_reference);

        if ($result['trx_id'] ?? null) {
            $transaction->update(['metadata' => array_merge($transaction->metadata ?? [], ['trx_id' => $result['trx_id']])]);
        }

        if ($result['success']) {
            $completion->complete($transaction);
            return;
        }

        // A genuinely failed/cancelled bKash payment reports this status explicitly —
        // trust it as definitive. Anything else (still "Initiated", or a transient
        // query error) is left pending for the next sweep, capped by the age ceiling
        // below so nothing sweeps forever.
        if ($result['status'] === 'Failed') {
            $completion->cancel($transaction);
            return;
        }

        if ($transaction->created_at->lt(now()->subDay())) {
            $completion->cancel($transaction);
        }
    }
}
