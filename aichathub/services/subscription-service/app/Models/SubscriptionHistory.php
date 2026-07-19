<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SubscriptionHistory extends Model
{
    use HasUuids;

    protected $table = 'subscription_history';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id', 'user_id', 'action',
        'old_package_id', 'new_package_id',
        'old_status', 'new_status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }
}
