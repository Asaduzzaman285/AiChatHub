<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'chat_sessions';

    protected $fillable = [
        'user_id', 'model_id', 'title', 'status',
        'message_count', 'total_tokens', 'total_cost',
    ];

    protected function casts(): array
    {
        return [
            'total_cost' => 'decimal:6',
        ];
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }
}
