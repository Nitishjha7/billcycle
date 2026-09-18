<?php

namespace App\Billing;

use App\Models\InvoiceSequence;
use Carbon\CarbonImmutable;

/**
 * Sequential, gapless invoice numbers: INV-2026-000123. Must be called from
 * inside the same database transaction that creates the invoice -- the row
 * lock from lockForUpdate() serialises allocation, and the shared
 * transaction means a rollback un-allocates the number rather than leaving
 * a hole. See docs/TECHNICAL_SPEC.md #7.
 */
final class InvoiceNumberGenerator
{
    public function next(CarbonImmutable $issuedAt): string
    {
        $year = $issuedAt->year;

        $sequence = InvoiceSequence::query()
            ->lockForUpdate()
            ->find($year);

        if ($sequence === null) {
            $sequence = InvoiceSequence::create(['year' => $year, 'last_number' => 0]);
            // A fresh row still needs the lock for the increment below to be
            // safe against a concurrent first-of-the-year invoice; re-fetch
            // it under the lock rather than trusting the just-created copy.
            $sequence = InvoiceSequence::query()->lockForUpdate()->find($year);
        }

        $sequence->last_number++;
        $sequence->save();

        return sprintf('INV-%d-%06d', $year, $sequence->last_number);
    }
}
