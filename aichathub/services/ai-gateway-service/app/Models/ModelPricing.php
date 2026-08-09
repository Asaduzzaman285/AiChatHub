<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ModelPricing extends Model
{
    use HasUuids;

    protected $table = 'model_pricing';
    const UPDATED_AT = null;

    // No $fillable existed before — harmless while nothing ever called
    // create()/update() with user input (only the seeder touched this table
    // directly via DB::table()). AiModelAdminController is the first real
    // caller, and mass assignment throws without this.
    protected $fillable = [
        'model_id', 'pricing_type', 'input_rate_per_million', 'output_rate_per_million',
        'flat_rate_per_unit', 'currency', 'effective_from', 'effective_until', 'is_active',
        'provider_input_rate_per_million', 'provider_output_rate_per_million',
        'provider_flat_rate_per_unit', 'markup_percentage',
    ];

    protected function casts(): array
    {
        return [
            'input_rate_per_million'  => 'decimal:6',
            'output_rate_per_million' => 'decimal:6',
            'flat_rate_per_unit'      => 'decimal:4',
            'is_active'               => 'boolean',
            'effective_from'          => 'datetime',
            'effective_until'         => 'datetime',
            'provider_input_rate_per_million'  => 'decimal:6',
            'provider_output_rate_per_million' => 'decimal:6',
            'provider_flat_rate_per_unit'      => 'decimal:4',
            'markup_percentage'                => 'decimal:2',
        ];
    }

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
