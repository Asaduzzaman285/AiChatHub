<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    use HasUuids;

    protected $table = 'packages';

    protected $fillable = [
        'name', 'slug', 'description',
        'monthly_price_usd', 'monthly_price_bdt',
        'monthly_wallet_credit_usd', 'credit_buffer_percentage',
        'model_access', 'features',
        'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'model_access' => 'array',
            'features'     => 'array',
            'is_active'    => 'boolean',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function allowsModel(string $modelId): bool
    {
        return in_array($modelId, $this->model_access ?? [], true);
    }

    /** Dollar amount of this package's wallet credit_limit buffer — see the 0002 migration's comment. */
    public function creditBufferAmount(): float
    {
        return round((float) $this->monthly_price_usd * ((float) $this->credit_buffer_percentage / 100), 2);
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }
}
