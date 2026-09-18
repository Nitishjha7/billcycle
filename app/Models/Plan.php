<?php

namespace App\Models;

use App\Billing\PlanPricing;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model implements PlanPricing
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'price_paise',
        'interval',
        'trial_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_paise' => 'integer',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function pricePaise(): int
    {
        return $this->price_paise;
    }

    public function planIdentity(): ?string
    {
        return $this->exists ? (string) $this->getKey() : null;
    }
}
