<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasUuids;

    protected $table = 'payment_methods';

    protected $fillable = [
        'user_id', 'gateway', 'type', 'token',
        'last_four', 'card_brand', 'bank_name', 'mobile_number',
        'expires_at', 'is_default', 'is_active',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'is_default' => 'boolean',
            'is_active'  => 'boolean',
        ];
    }
}
