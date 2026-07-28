<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Table existed, migrated, since before this session — nothing read or wrote
 * it until now. Backs the `is_admin` JWT claim (see User::getJWTCustomClaims())
 * and gates the new admin-only endpoints via X-Is-Admin (forwarded by
 * api-gateway's JwtGatewayMiddleware, checked by each service's AdminGateMiddleware).
 */
class AdminUser extends Model
{
    use HasUuids;

    protected $table = 'admin_users';
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'role', 'permissions', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active'   => 'boolean',
            'created_at'  => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
