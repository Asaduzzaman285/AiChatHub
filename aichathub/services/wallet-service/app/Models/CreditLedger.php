<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CreditLedger extends Model
{
    use HasUuids;

    protected $table = 'credit_ledger';

    public $timestamps = false;

    protected $fillable = [
        'wallet_id', 'user_id', 'type', 'amount',
        'credit_balance_before', 'credit_balance_after',
        'description', 'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'amount'                => 'decimal:6',
            'credit_balance_before' => 'decimal:6',
            'credit_balance_after'  => 'decimal:6',
            'created_at'            => 'datetime',
        ];
    }
}
