<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RenewalAttempt extends Model
{
    use HasUuids;

    protected $table = 'renewal_attempts';

    public $timestamps = false;

    protected $fillable = [
        'subscription_id', 'user_id', 'attempt_number',
        'scheduled_at', 'attempted_at', 'success',
        'error_message', 'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'attempted_at' => 'datetime',
            'success'      => 'boolean',
            'created_at'   => 'datetime',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }
}
