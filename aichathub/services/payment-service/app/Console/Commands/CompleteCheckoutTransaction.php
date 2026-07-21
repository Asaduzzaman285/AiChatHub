<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\CheckoutCompletionService;
use Illuminate\Console\Command;

/**
 * One-off manual-recovery tool for a transaction whose Checkout Session is
 * confirmed paid on Stripe's side but never got processed (e.g. because the
 * frontend's verify-on-return call never fired and no webhook is configured
 * locally). Runs the exact same completion path CheckoutController::verify()
 * and the webhook job use, so it's safe to run even if the transaction was
 * already completed by another path (no-ops).
 */
class CompleteCheckoutTransaction extends Command
{
    protected $signature = 'checkout:complete {transaction_id}';
    protected $description = 'Manually run CheckoutCompletionService::complete() for a given transaction';

    public function handle(CheckoutCompletionService $completion): int
    {
        $transaction = Transaction::find($this->argument('transaction_id'));

        if (! $transaction) {
            $this->error('Transaction not found.');
            return self::FAILURE;
        }

        $this->info("Before: status={$transaction->status}");
        $completion->complete($transaction);
        $transaction->refresh();
        $this->info("After: status={$transaction->status}");

        return self::SUCCESS;
    }
}
