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
        'monthly_wallet_credit_usd',
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

    public function hasFeature(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }
}
