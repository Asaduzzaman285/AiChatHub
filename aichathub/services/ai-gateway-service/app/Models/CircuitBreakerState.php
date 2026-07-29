<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CircuitBreakerState extends Model
{
    use HasUuids;

    protected $table = 'circuit_breaker_state';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'opened_at'     => 'datetime',
            'next_probe_at' => 'datetime',
            'updated_at'    => 'datetime',
        ];
    }

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
