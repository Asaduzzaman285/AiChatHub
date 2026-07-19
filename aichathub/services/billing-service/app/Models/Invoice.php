<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasUuids;

    protected $table = 'invoices';

    protected $fillable = [
        'user_id', 'subscription_id', 'invoice_number', 'type',
        'amount', 'currency', 'tax_amount', 'total_amount',
        'status', 'transaction_id', 'pdf_url', 'issued_at', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'tax_amount'   => 'decimal:2',
            'total_amount' => 'decimal:2',
            'issued_at'    => 'datetime',
            'paid_at'      => 'datetime',
        ];
    }
}
