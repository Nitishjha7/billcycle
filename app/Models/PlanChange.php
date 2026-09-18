<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanChange extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'subscription_id',
        'from_plan_id',
        'to_plan_id',
        'changed_at',
        'credit_paise',
        'charge_paise',
        'net_paise',
        'applied_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
            'credit_paise' => 'integer',
            'charge_paise' => 'integer',
            'net_paise' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    public function appliedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'applied_invoice_id');
    }
}
