<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasUuids;

    protected $table = 'webhook_events';

    public $timestamps = false;

    protected $fillable = [
        'gateway', 'event_type', 'gateway_reference', 'status',
        'payload', 'processed_at', 'error_message', 'transaction_id', 'retry_count',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed_at' => 'datetime',
            'created_at'   => 'datetime',
        ];
    }
}
