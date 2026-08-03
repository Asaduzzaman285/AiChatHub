<?php

namespace App\Console\Commands;

use App\Models\WalletLedgerEntry;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safety net for reserve()'s idempotency fix, not a replacement for it. A
 * reservation normally gets settled by a deduct() or refund() moments later —
 * but if ai-gateway's HTTP call to /wallet/reserve times out on the client
 * side while actually succeeding server-side, ai-gateway never finds out the
 * reservation happened, so neither PendingReservationTracker's terminating()
 * hook nor CostTrackingMiddleware's own catch block ever fires for it. This
 * sweep is the independent, self-contained backup: it only trusts what's
 * already in this service's own ledger, not anything ai-gateway remembers.
 */
class ReconcileWalletReservationsCommand extends Command
{
    protected $signature   = 'wallet:reconcile-reservations';
    protected $description = 'Release wallet reservations that were never settled by a deduct or refund';

    public function handle(WalletService $walletService): void
    {
        $settledReferenceIds = WalletLedgerEntry::whereIn('type', ['debit', 'refund'])
            ->whereNotNull('reference_id')
            ->pluck('reference_id');

        $stale = WalletLedgerEntry::where('type', 'reserve')
            ->where('created_at', '<', now()->subMinutes(15))
            ->whereNotNull('reference_id')
            ->whereNotIn('reference_id', $settledReferenceIds)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale wallet reservations.');
            return;
        }

        $released = 0;

        foreach ($stale as $entry) {
            try {
                $walletService->refund(
                    $entry->user_id,
                    0,
                    (float) $entry->amount,
                    'Reservation reconciliation sweep — never settled within 15 minutes',
                    $entry->reference_id,
                );
                $released++;
            } catch (\Throwable $e) {
                Log::error('Wallet reservation reconciliation failed for one entry', [
                    'reference_id' => $entry->reference_id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        $this->info("Reconciled {$released}/{$stale->count()} stale wallet reservation(s).");
    }
}
