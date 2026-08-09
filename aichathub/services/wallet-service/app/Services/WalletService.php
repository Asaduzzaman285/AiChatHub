<?php

namespace App\Services;

use App\Jobs\TriggerAutoDebitJob;
use App\Models\AutoDebitSetting;
use App\Models\CreditLedger;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class WalletService
{
    /**
     * Create a wallet for a new user.
     * Called when user.registered event is received.
     */
    public function createForUser(string $userId, string $currency = 'USD'): Wallet
    {
        // No credit buffer until the user actually buys a package — see credit()'s
        // $activateCreditBuffer param, called from subscription-service's subscribe().
        return Wallet::firstOrCreate(
            ['user_id' => $userId],
            ['currency' => $currency, 'credit_limit' => 0]
        );
    }

    /**
     * Credit wallet — settles any outstanding credit first, then adds remainder.
     * Used for: top-up, subscription purchase/renewal, refund.
     *
     * Idempotent on (reference_type, reference_id) when both are given: callers
     * (e.g. subscription-service, the Stripe webhook job) time out and retry on
     * a slow response even when the credit already landed server-side — without
     * this guard a retry double-credits the wallet.
     */
    public function credit(string $userId, float $amount, string $description, string $referenceType = null, string $referenceId = null, ?float $creditLimit = null, string $type = 'credit'): Wallet
    {
        return DB::transaction(function () use ($userId, $amount, $description, $referenceType, $referenceId, $creditLimit, $type) {
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            // Idempotency check happens after the row lock so two concurrent
            // retries for the same reference serialize correctly instead of
            // racing past the check together.
            if ($referenceType && $referenceId) {
                $alreadyCredited = WalletLedgerEntry::where('type', $type)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->exists();

                if ($alreadyCredited) {
                    return $wallet;
                }
            }

            // Package buyers get a grace buffer (see WalletService::deduct()) so a
            // mid-request $0 balance doesn't hard-block a request — sized as a
            // percentage of the package's price, computed by the caller
            // (subscription-service knows which package; this service doesn't).
            // Only ever raised, never lowered here: shrinking the ceiling while
            // credit_balance is already negative under a higher limit (e.g. a
            // downgrade) would violate the credit_balance >= -credit_limit DB
            // constraint. A lower buffer takes effect once any debt is repaid
            // naturally, not by force. $creditLimit is null for anything that
            // isn't a subscription event (top-ups, refunds) — untouched then.
            if ($creditLimit !== null) {
                $wallet->credit_limit = max((float) $wallet->credit_limit, $creditLimit);
            }

            $balanceBefore = (float) $wallet->balance;
            $remaining     = $amount;

            // ── Step 1: Settle credit debt first ──────────────────────────
            if ($wallet->credit_balance < 0) {
                $owed       = abs((float) $wallet->credit_balance);
                $settlement = min($remaining, $owed);

                $creditBefore = (float) $wallet->credit_balance;
                $wallet->credit_balance = (float) $wallet->credit_balance + $settlement;
                $remaining -= $settlement;

                CreditLedger::create([
                    'wallet_id'            => $wallet->id,
                    'user_id'              => $userId,
                    'type'                 => 'credit_recovered',
                    'amount'               => $settlement,
                    'credit_balance_before'=> $creditBefore,
                    'credit_balance_after' => (float) $wallet->credit_balance,
                    'description'          => "Credit recovered from: {$description}",
                    'reference_id'         => $referenceId,
                ]);
            }

            // ── Step 2: Add remaining to balance ──────────────────────────
            $wallet->balance = (float) $wallet->balance + $remaining;
            $wallet->save();

            WalletLedgerEntry::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => $type,
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => (float) $wallet->balance,
                'description'    => $description,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $this->broadcastBalanceUpdate($userId, $wallet);

            return $wallet;
        });
    }

    /**
     * Reserve estimated cost before sending AI request.
     * Returns false if insufficient funds.
     *
     * Idempotent on reference_id when given — same reasoning as deduct()/refund():
     * ai-gateway's HTTP call to this endpoint can time out even though the
     * reservation landed server-side, and a naive retry (or the reconciliation
     * sweep re-checking later) would double-reserve without this guard. Unlike
     * the other three methods, reserve() previously wrote nothing to
     * wallet_ledger_entries at all — the ledger row added here is also what
     * ReconcileWalletReservationsCommand keys off of to find and release
     * reservations that were never followed by a deduct()/refund() at all
     * (e.g. the client-side timeout scenario above, where ai-gateway never even
     * finds out the reservation succeeded, so its own release paths never fire).
     */
    public function reserve(string $userId, float $amount, ?string $referenceId = null): bool
    {
        return DB::transaction(function () use ($userId, $amount, $referenceId) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if ($referenceId) {
                $alreadyReserved = WalletLedgerEntry::where('type', 'reserve')
                    ->where('reference_type', 'ai_usage_reservation')
                    ->where('reference_id', $referenceId)
                    ->exists();

                if ($alreadyReserved) {
                    return true;
                }
            }

            if (! $wallet->canAfford($amount)) {
                return false;
            }

            $wallet->reserved_balance = (float) $wallet->reserved_balance + $amount;
            $wallet->save();

            // balance_before/after both reflect the current balance — reserve()
            // never touches `balance`, only `reserved_balance`; these columns are
            // still populated (not null) for consistency with every other ledger
            // entry type and so a raw balance query against this table stays sane.
            WalletLedgerEntry::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => 'reserve',
                'amount'         => $amount,
                'balance_before' => (float) $wallet->balance,
                'balance_after'  => (float) $wallet->balance,
                'description'    => 'AI request cost reservation',
                'reference_type' => $referenceId ? 'ai_usage_reservation' : null,
                'reference_id'   => $referenceId,
            ]);

            return true;
        });
    }

    /**
     * Deduct actual cost after AI request completes.
     * Releases reservation and charges actual amount.
     *
     * Idempotent on (reference_type, reference_id) when both are given — same
     * reasoning as credit(): a caller can time out waiting for this call even
     * though it completed server-side, and a naive retry would double-deduct.
     */
    public function deduct(string $userId, float $actualCost, float $reservedAmount, string $description, string $referenceType = null, string $referenceId = null, string $type = 'debit'): void
    {
        DB::transaction(function () use ($userId, $actualCost, $reservedAmount, $description, $referenceType, $referenceId, $type) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if ($referenceType && $referenceId) {
                $alreadyDeducted = WalletLedgerEntry::where('type', $type)
                    ->where('reference_type', $referenceType)
                    ->where('reference_id', $referenceId)
                    ->exists();

                if ($alreadyDeducted) {
                    return;
                }
            }

            $balanceBefore = (float) $wallet->balance;

            // Release reservation
            $wallet->reserved_balance = max(0, (float) $wallet->reserved_balance - $reservedAmount);

            // Deduct from balance — use credit buffer if balance insufficient
            if ((float) $wallet->balance >= $actualCost) {
                $wallet->balance = (float) $wallet->balance - $actualCost;
            } else {
                $shortage = $actualCost - (float) $wallet->balance;
                $creditBefore = (float) $wallet->credit_balance;

                $wallet->balance       = 0;
                $wallet->credit_balance = (float) $wallet->credit_balance - $shortage;

                CreditLedger::create([
                    'wallet_id'             => $wallet->id,
                    'user_id'               => $userId,
                    'type'                  => 'credit_used',
                    'amount'                => $shortage,
                    'credit_balance_before' => $creditBefore,
                    'credit_balance_after'  => (float) $wallet->credit_balance,
                    'description'           => "Credit buffer used: {$description}",
                    'reference_id'          => $referenceId,
                ]);
            }

            $wallet->save();

            WalletLedgerEntry::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => $type,
                'amount'         => $actualCost,
                'balance_before' => $balanceBefore,
                'balance_after'  => (float) $wallet->balance,
                'description'    => $description,
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
            ]);

            $this->broadcastBalanceUpdate($userId, $wallet);

            // Fire low/critical balance events
            $this->checkBalanceThresholds($userId, $wallet);
        });
    }

    /**
     * Refund cost on failed AI request.
     *
     * Idempotent on reference_id (type is always 'usage_log' for a refund) —
     * ReleaseWalletReservationJob retries up to 3 times on failure, and without
     * this guard a retry that actually succeeded server-side but timed out
     * client-side would refund the same reservation twice.
     */
    public function refund(string $userId, float $amount, float $reservedAmount, string $reason, string $referenceId = null): void
    {
        DB::transaction(function () use ($userId, $amount, $reservedAmount, $reason, $referenceId) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if ($referenceId) {
                $alreadyRefunded = WalletLedgerEntry::where('type', 'refund')
                    ->where('reference_type', 'usage_log')
                    ->where('reference_id', $referenceId)
                    ->exists();

                if ($alreadyRefunded) {
                    return;
                }
            }

            $balanceBefore = (float) $wallet->balance;
            $wallet->reserved_balance = max(0, (float) $wallet->reserved_balance - $reservedAmount);

            // Credit back (settle credit first if negative)
            if ($wallet->credit_balance < 0) {
                $owed       = abs((float) $wallet->credit_balance);
                $settlement = min($amount, $owed);
                $wallet->credit_balance = (float) $wallet->credit_balance + $settlement;
                $amount -= $settlement;
            }
            $wallet->balance = (float) $wallet->balance + $amount;
            $wallet->save();

            WalletLedgerEntry::create([
                'wallet_id'      => $wallet->id,
                'user_id'        => $userId,
                'type'           => 'refund',
                'amount'         => $amount,
                'balance_before' => $balanceBefore,
                'balance_after'  => (float) $wallet->balance,
                'description'    => "Refund: {$reason}",
                'reference_type' => 'usage_log',
                'reference_id'   => $referenceId,
            ]);

            $this->broadcastBalanceUpdate($userId, $wallet);
        });
    }

    private function broadcastBalanceUpdate(string $userId, Wallet $wallet): void
    {
        Redis::publish('wallet-events', json_encode([
            'event'   => 'wallet.balance_updated',
            'payload' => [
                'user_id'           => $userId,
                'balance'           => (float) $wallet->balance,
                'credit_balance'    => (float) $wallet->credit_balance,
                'available_balance' => $wallet->availableBalance(),
            ],
        ]));
    }

    private function checkBalanceThresholds(string $userId, Wallet $wallet): void
    {
        $lowThreshold      = (float) config('wallet.low_balance_threshold', 5.00);
        $criticalThreshold = (float) config('wallet.critical_balance_threshold', 1.00);
        $balance           = (float) $wallet->balance;

        $this->maybeTriggerAutoDebit($userId, $balance);

        if ($balance <= $criticalThreshold) {
            Redis::publish('wallet-events', json_encode([
                'event'   => 'wallet.balance_critical',
                'payload' => ['user_id' => $userId, 'balance' => $balance],
            ]));
            $this->notifyLowBalance($userId, $balance, critical: true);
        } elseif ($balance <= $lowThreshold) {
            Redis::publish('wallet-events', json_encode([
                'event'   => 'wallet.balance_low',
                'payload' => ['user_id' => $userId, 'balance' => $balance, 'threshold' => $lowThreshold],
            ]));
            $this->notifyLowBalance($userId, $balance, critical: false);
        }
    }

    /**
     * Queued, never synchronous — deduct() is the live chat-streaming hot path and must
     * never block on a Stripe round-trip. TriggerAutoDebitJob re-checks `enabled` itself
     * on execution, so dispatching here on every under-threshold deduct (not just the
     * moment it crosses) is safe — a disabled/already-topped-up setting is just a no-op.
     */
    private function maybeTriggerAutoDebit(string $userId, float $balance): void
    {
        $setting = AutoDebitSetting::where('user_id', $userId)->where('enabled', true)->first();

        if ($setting && $balance < (float) $setting->threshold_usd) {
            TriggerAutoDebitJob::dispatch($userId);
        }
    }

    /**
     * At most one email per threshold level per day — this fires on every deduct()
     * while the balance stays under threshold, not just the moment it crosses it.
     */
    private function notifyLowBalance(string $userId, float $balance, bool $critical): void
    {
        $user = app(AuthServiceClient::class)->findUser($userId);
        if (! $user) {
            return;
        }

        $level = $critical ? 'critical' : 'low';
        app(NotificationClient::class)->send(
            'low_balance',
            $userId,
            $user['email'],
            ['name' => $user['name'], 'balance' => $balance, 'critical' => $critical],
            "low_balance:{$userId}:{$level}:".now()->format('Y-m-d'),
        );
    }
}
