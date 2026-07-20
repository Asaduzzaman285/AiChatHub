<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasUuids;

    protected $table = 'notifications';
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'type', 'channel', 'subject', 'content', 'metadata',
        'status', 'sent_at', 'failed_at', 'error_message', 'retry_count', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'sent_at'    => 'datetime',
            'failed_at'  => 'datetime',
            'opened_at'  => 'datetime',
        ];
    }
}
