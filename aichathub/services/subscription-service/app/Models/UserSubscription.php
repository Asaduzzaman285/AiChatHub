<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserSubscription extends Model
{
    use HasUuids;

    protected $table = 'user_subscriptions';

    protected $fillable = [
        'user_id', 'package_id', 'previous_package_id',
        'scheduled_package_id', 'payment_method_id',
        'status', 'auto_renew', 'currency', 'exchange_rate',
        'activated_at', 'renews_at',
        'cancelled_at', 'cancellation_reason', 'past_due_at',
    ];

    protected function casts(): array
    {
        return [
            'auto_renew'    => 'boolean',
            'activated_at'  => 'datetime',
            'renews_at'     => 'datetime',
            'cancelled_at'  => 'datetime',
            'past_due_at'   => 'datetime',
        ];
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function history()
    {
        return $this->hasMany(SubscriptionHistory::class, 'subscription_id');
    }

    public function renewalAttempts()
    {
        return $this->hasMany(RenewalAttempt::class, 'subscription_id');
    }

    public function isActive(): bool   { return $this->status === 'active'; }
    public function isPastDue(): bool  { return $this->status === 'past_due'; }
    public function isCancelled(): bool{ return $this->status === 'cancelled'; }
    public function isDueForRenewal(): bool { return $this->renews_at->isPast() && $this->auto_renew; }
}
