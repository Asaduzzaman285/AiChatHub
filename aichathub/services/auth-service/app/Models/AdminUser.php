<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Table existed, migrated, since before this session — nothing read or wrote
 * it until now. Backs the `is_admin` JWT claim (see User::getJWTCustomClaims())
 * and gates the new admin-only endpoints via X-Is-Admin (forwarded by
 * api-gateway's JwtGatewayMiddleware, checked by each service's AdminGateMiddleware).
 *
 * `permissions` is no longer a real column (dropped by 0002_create_roles_table) —
 * it's resolved live from the related Role, so a role's permissions can be
 * edited once and apply to every admin on it immediately, rather than being
 * frozen on each admin_users row at assignment time.
 */
class AdminUser extends Model
{
    use HasUuids;

    protected $table = 'admin_users';
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'role', 'is_active',
    ];

    // permissions is a computed accessor (see below), not a real column — must
    // be explicitly appended or it silently disappears from toArray()/JSON
    // responses, which the frontend still expects on every admin list item.
    protected $appends = ['permissions'];

    // Accessing the accessor lazy-loads + caches roleRecord as a side effect,
    // which Eloquent then also auto-includes in toArray()/JSON output as
    // "role_record" unless hidden — redundant noise, permissions already
    // carries everything the API contract needs.
    protected $hidden = ['roleRecord'];

    protected function casts(): array
    {
        return [
            'is_active'  => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Named roleRecord(), not role() — a relation method named the same as the
     * real `role` string column would never actually fire on property access
     * (Eloquent checks real attributes before relation methods), silently
     * making `$this->role` always return the raw string even from inside this
     * class. Matched by name, not id — admin_users.role is a plain string
     * FK-by-name to roles.name.
     */
    public function roleRecord()
    {
        return $this->belongsTo(Role::class, 'role', 'name');
    }

    protected function permissions(): Attribute
    {
        return Attribute::get(fn () => $this->roleRecord?->permissions ?? []);
    }
}
