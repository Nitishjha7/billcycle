<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Invoice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'subscription_id',
        'number',
        'period_start',
        'period_end',
        'subtotal_paise',
        'total_paise',
        'status',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'subtotal_paise' => 'integer',
            'total_paise' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Invoice $invoice) {
            // A paid invoice is a financial record, not a draft -- it must
            // not be silently edited after the fact. Voiding it is still
            // allowed (see void()); that flips status, it doesn't rewrite
            // the amounts.
            if ($invoice->exists && $invoice->getOriginal('status') === 'paid') {
                $dirty = array_keys($invoice->getDirty());
                $onlyStatusChanged = $dirty === ['status'] && $invoice->status === 'void';

                if (! $onlyStatusChanged) {
                    throw new LogicException('A paid invoice cannot be modified.');
                }
            }
        });
    }

    /**
     * Voiding does not delete the invoice -- the record stays, only its
     * status changes. See docs/TEST_PLAN.md #5.
     */
    public function void(): void
    {
        $this->update(['status' => 'void']);
    }
}
