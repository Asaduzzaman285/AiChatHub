<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Table existed, migrated, written to via raw DB::table() inserts in
 * UsageLoggingMiddleware — no Eloquent model until now. Read-only here
 * (admin listing); the write path stays as-is.
 */
class UsageLog extends Model
{
    use HasUuids;

    protected $table = 'usage_logs';
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function model()
    {
        return $this->belongsTo(AiModel::class, 'model_id');
    }
}
