<?php

namespace App\Services;

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
        return Wallet::firstOrCreate(
            ['user_id' => $userId],
            ['currency' => $currency, 'credit_limit' => config('wallet.credit_buffer_default', 3.00)]
        );
    }

    /**
     * Credit wallet — settles any outstanding credit first, then adds remainder.
     * Used for: top-up, subscription purchase/renewal, refund.
     */
    public function credit(string $userId, float $amount, string $description, string $referenceType = null, string $referenceId = null): Wallet
    {
        return DB::transaction(function () use ($userId, $amount, $description, $referenceType, $referenceId) {
            /** @var Wallet $wallet */
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

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
                'type'           => 'credit',
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
     */
    public function reserve(string $userId, float $amount): bool
    {
        return DB::transaction(function () use ($userId, $amount) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if (! $wallet->canAfford($amount)) {
                return false;
            }

            $wallet->reserved_balance = (float) $wallet->reserved_balance + $amount;
            $wallet->save();

            return true;
        });
    }

    /**
     * Deduct actual cost after AI request completes.
     * Releases reservation and charges actual amount.
     */
    public function deduct(string $userId, float $actualCost, float $reservedAmount, string $description, string $referenceType = null, string $referenceId = null): void
    {
        DB::transaction(function () use ($userId, $actualCost, $reservedAmount, $description, $referenceType, $referenceId) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

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
                'type'           => 'debit',
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
     */
    public function refund(string $userId, float $amount, float $reservedAmount, string $reason, string $referenceId = null): void
    {
        DB::transaction(function () use ($userId, $amount, $reservedAmount, $reason, $referenceId) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();

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

        if ((float) $wallet->balance <= $criticalThreshold) {
            Redis::publish('wallet-events', json_encode([
                'event'   => 'wallet.balance_critical',
                'payload' => ['user_id' => $userId, 'balance' => (float) $wallet->balance],
            ]));
        } elseif ((float) $wallet->balance <= $lowThreshold) {
            Redis::publish('wallet-events', json_encode([
                'event'   => 'wallet.balance_low',
                'payload' => ['user_id' => $userId, 'balance' => (float) $wallet->balance, 'threshold' => $lowThreshold],
            ]));
        }
    }
}
