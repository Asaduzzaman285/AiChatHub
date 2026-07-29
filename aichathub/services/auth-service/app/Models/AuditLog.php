<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Table existed, migrated, since before this session — nothing wrote to it
 * until now. Append-only: written once via AuditLogInternalController@store
 * (auth-service writes directly; every other service posts to
 * /internal/audit-logs instead of touching this table, since it doesn't
 * have its own copy of it).
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_logs';
    public $timestamps = false;

    protected $fillable = [
        'admin_user_id', 'actor_type', 'action', 'resource_type',
        'resource_id', 'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function adminUser()
    {
        return $this->belongsTo(AdminUser::class);
    }
}
