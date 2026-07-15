<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    use HasUuids;

    protected $table = 'login_attempts';

    public $timestamps = false;

    protected $fillable = [
        'email',
        'ip_address',
        'success',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'success'    => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
