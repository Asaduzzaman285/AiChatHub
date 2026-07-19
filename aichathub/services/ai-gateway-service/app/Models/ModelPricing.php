<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ModelPricing extends Model
{
    use HasUuids;

    protected $table = 'model_pricing';
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'input_rate_per_million'  => 'decimal:6',
            'output_rate_per_million' => 'decimal:6',
            'flat_rate_per_unit'      => 'decimal:4',
            'is_active'               => 'boolean',
            'effective_from'          => 'datetime',
            'effective_until'         => 'datetime',
        ];
    }

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
