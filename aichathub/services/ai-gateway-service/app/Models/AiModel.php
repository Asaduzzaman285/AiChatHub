<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiModel extends Model
{
    use HasUuids;

    protected $table = 'ai_models';
    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active'    => 'boolean',
        ];
    }

    public function pricing()
    {
        return $this->hasMany(ModelPricing::class, 'model_id');
    }

    public function activePricing(): ?ModelPricing
    {
        return $this->pricing()
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', now()))
            ->latest('effective_from')
            ->first();
    }
}
