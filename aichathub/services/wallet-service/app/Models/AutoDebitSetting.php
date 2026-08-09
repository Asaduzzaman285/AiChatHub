<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AutoDebitSetting extends Model
{
    use HasUuids;

    protected $table = 'auto_debit_settings';

    protected $fillable = [
        'user_id', 'enabled', 'threshold_usd', 'topup_amount_usd', 'payment_method_id',
    ];

    protected function casts(): array
    {
        return [
            'enabled'          => 'boolean',
            'threshold_usd'    => 'decimal:2',
            'topup_amount_usd' => 'decimal:2',
        ];
    }
}
