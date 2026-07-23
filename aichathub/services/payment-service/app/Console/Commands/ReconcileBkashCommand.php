<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileBkashPaymentJob;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * bKash's tokenized Checkout has no server-to-server webhook (unlike Stripe) —
 * verify-on-return (CheckoutController::verify()) is the only completion path,
 * so a transaction where the browser never returns stays pending forever with
 * nothing else to reconcile it. This sweep is that missing backup: anything
 * still pending past 15 minutes gets queried directly against bKash.
 */
class ReconcileBkashCommand extends Command
{
    protected $signature   = 'bkash:reconcile';
    protected $description = 'Sweep stuck-pending bKash transactions and resolve them via queryPayment';

    public function handle(): void
    {
        $stuck = Transaction::where('gateway', 'bkash')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(15))
            ->get();

        if ($stuck->isEmpty()) {
            $this->info('No stuck bKash transactions.');
            return;
        }

        foreach ($stuck as $transaction) {
            ReconcileBkashPaymentJob::dispatch($transaction->id);
        }

        $this->info("Dispatched {$stuck->count()} bKash reconciliation job(s).");
    }
}
