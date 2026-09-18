<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per year: (year, last_number). Read with SELECT ... FOR UPDATE
 * inside the same transaction that creates an invoice, so the row lock
 * serialises number allocation and a rollback un-allocates the number.
 * See docs/TECHNICAL_SPEC.md #7.
 */
class InvoiceSequence extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    // The migration deliberately has no timestamp columns -- this is a
    // narrow counter row, not a record with a history worth tracking.
    public $timestamps = false;

    protected $fillable = [
        'year',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
