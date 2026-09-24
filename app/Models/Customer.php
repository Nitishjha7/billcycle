<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'email',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The three ledger invariants exercised by tests/Feature/ChaosTest.php:
     * invoiced minus paid must always equal outstanding, no matter what
     * sequence of events produced them. Void invoices are excluded from all
     * three -- a voided invoice was never really owed.
     */
    public function invoicedTotalPaise(): int
    {
        return (int) Invoice::query()
            ->whereIn('subscription_id', $this->subscriptions()->pluck('id'))
            ->where('status', '!=', 'void')
            ->sum('total_paise');
    }

    public function paidTotalPaise(): int
    {
        $invoiceIds = Invoice::query()
            ->whereIn('subscription_id', $this->subscriptions()->pluck('id'))
            ->where('status', '!=', 'void')
            ->pluck('id');

        return (int) Payment::query()
            ->whereIn('invoice_id', $invoiceIds)
            ->where('status', 'succeeded')
            ->sum('amount_paise');
    }

    public function outstandingPaise(): int
    {
        return (int) Invoice::query()
            ->whereIn('subscription_id', $this->subscriptions()->pluck('id'))
            ->where('status', 'open')
            ->sum('total_paise');
    }
}
