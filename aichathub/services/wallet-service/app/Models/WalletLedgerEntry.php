<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WalletLedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'wallet_ledger_entries';

    public $timestamps = false;

    protected $fillable = [
        'wallet_id', 'user_id', 'type', 'amount',
        'balance_before', 'balance_after',
        'description', 'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'         => 'decimal:6',
            'balance_before' => 'decimal:6',
            'balance_after'  => 'decimal:6',
            'created_at'     => 'datetime',
        ];
    }
}
