<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasUuids;

    protected $table = 'currencies';

    protected $fillable = [
        'code', 'name', 'symbol', 'decimal_places', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active'      => 'boolean',
        ];
    }

    public function rates()
    {
        return $this->hasMany(CurrencyRate::class, 'currency_code', 'code');
    }

    public function activeRate(): ?CurrencyRate
    {
        return $this->rates()
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
            ->latest('effective_from')
            ->first();
    }
}
