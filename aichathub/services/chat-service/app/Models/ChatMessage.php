<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasUuids;

    protected $table = 'chat_messages';
    const UPDATED_AT = null;

    protected $fillable = [
        'session_id', 'user_id', 'role', 'content',
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost',
        'usage_log_id', 'provider_message_id', 'is_streaming', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'cost'         => 'decimal:6',
            'is_streaming' => 'boolean',
            'metadata'     => 'array',
        ];
    }

    public function session()
    {
        return $this->belongsTo(ChatSession::class, 'session_id');
    }
}
