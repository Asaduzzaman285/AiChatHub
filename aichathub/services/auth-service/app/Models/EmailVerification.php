<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmailVerification extends Model
{
    use HasUuids;

    protected $table = 'email_verifications';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token',
        'used',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'used'       => 'boolean',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
