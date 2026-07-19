<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasUuids;

    protected $table = 'transactions';

    protected $fillable = [
        'user_id', 'type', 'status', 'amount', 'currency', 'exchange_rate',
        'gateway', 'gateway_reference', 'payment_method_id', 'idempotency_key',
        'description', 'metadata', 'error_message', 'gateway_fee', 'net_amount',
        'completed_at', 'failed_at', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'gateway_fee'   => 'decimal:2',
            'net_amount'    => 'decimal:2',
            'metadata'      => 'array',
            'completed_at'  => 'datetime',
            'failed_at'     => 'datetime',
            'refunded_at'   => 'datetime',
        ];
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
