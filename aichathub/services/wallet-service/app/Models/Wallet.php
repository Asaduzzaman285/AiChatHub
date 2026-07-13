<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    use HasUuids;

    protected $table = 'wallets';

    protected $fillable = [
        'user_id', 'balance', 'reserved_balance',
        'credit_balance', 'credit_limit', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance'          => 'decimal:6',
            'reserved_balance' => 'decimal:6',
            'credit_balance'   => 'decimal:6',
            'credit_limit'     => 'decimal:2',
        ];
    }

    public function ledgerEntries()
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    public function creditLedger()
    {
        return $this->hasMany(CreditLedger::class);
    }

    /**
     * Total spendable: wallet balance + unused credit allowance.
     */
    public function availableBalance(): float
    {
        $unusedCredit = $this->credit_limit - abs($this->credit_balance);
        return (float) $this->balance + max(0, $unusedCredit);
    }

    /**
     * Whether a given amount can be spent right now.
     */
    public function canAfford(float $amount): bool
    {
        return $this->availableBalance() >= $amount;
    }

    /**
     * Remaining credit headroom (how much more we can go negative).
     */
    public function remainingCredit(): float
    {
        return max(0, (float) $this->credit_limit - abs((float) $this->credit_balance));
    }
}
