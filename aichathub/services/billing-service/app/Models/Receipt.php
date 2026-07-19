<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Receipt extends Model
{
    use HasUuids;

    protected $table = 'receipts';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'receipt_number', 'type', 'amount', 'currency',
        'transaction_id', 'pdf_url', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'issued_at'  => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
