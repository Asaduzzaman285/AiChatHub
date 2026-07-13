<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'email',
        'password',
        'name',
        'phone',
        'status',
        'preferred_currency',
        'avatar_url',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'deleted_at'        => 'datetime',
            'password'          => 'hashed',
        ];
    }

    // ── JWTSubject Interface ─────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'email'  => $this->email,
            'status' => $this->status,
            'name'   => $this->name,
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function socialAccounts()
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function googleAccount()
    {
        return $this->hasOne(SocialAccount::class)->where('provider', 'google');
    }

    public function refreshTokens()
    {
        return $this->hasMany(RefreshToken::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function hasPassword(): bool
    {
        return ! is_null($this->password);
    }

    public function isEmailVerified(): bool
    {
        return ! is_null($this->email_verified_at);
    }
}
