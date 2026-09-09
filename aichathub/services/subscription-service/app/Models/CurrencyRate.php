<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    use HasUuids;

    protected $table = 'currency_rates';

    protected $fillable = [
        'currency_code', 'exchange_rate',
        'margin_percentage', 'tax_percentage', 'vat_percentage', 'withholding_tax_percentage',
        'effective_rate', 'effective_from', 'effective_until', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate'              => 'decimal:6',
            'margin_percentage'          => 'decimal:2',
            'tax_percentage'             => 'decimal:2',
            'vat_percentage'             => 'decimal:2',
            'withholding_tax_percentage' => 'decimal:2',
            'effective_rate'             => 'decimal:6',
            'effective_from'             => 'datetime',
            'effective_until'            => 'datetime',
            'is_active'                  => 'boolean',
        ];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * A 4-step cascade, each step compounding on the previous result — not a
     * flat percentage stack on the base rate. Mirrors the admin's own
     * spreadsheet exactly (verified against it: 126 -> 138.6 -> 154 -> 161.7
     * -> 202.125 for margin 10 / tax 10 / vat 5 / withholding 25):
     *
     *   1. Margin      — additive:  afterMargin = rate * (1 + margin/100)
     *   2. Tax         — gross-up:  afterTax    = afterMargin / (1 - tax/100)
     *                    ("tax-inclusive" — afterMargin is treated as the
     *                    post-tax amount minus tax, not a base to add tax onto)
     *   3. VAT         — additive:  afterVat    = afterTax * (1 + vat/100)
     *   4. Withholding — additive:  afterWithholding = afterVat * (1 + withholding/100)
     *
     * The result is effective_rate: how many units of the currency 1 USD
     * converts to. The reverse direction (currency -> USD) is simply
     * amount / effective_rate — no separate formula or stored rate needed.
     *
     * Kept here (not just inline in the admin controller) so anything that
     * ever needs to preview the number before saving computes it the exact
     * same way.
     */
    public static function computeEffectiveRate(float $exchangeRate, float $margin, float $tax, float $vat, float $withholding): float
    {
        $afterMargin = $exchangeRate * (1 + $margin / 100);
        $afterTax = $afterMargin / (1 - $tax / 100);
        $afterVat = $afterTax * (1 + $vat / 100);
        $afterWithholding = $afterVat * (1 + $withholding / 100);

        return round($afterWithholding, 6);
    }
}
