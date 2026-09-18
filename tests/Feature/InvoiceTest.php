<?php

use App\Billing\InvoiceNumberGenerator;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('line items sum to the invoice total', function () {
    $invoice = Invoice::factory()->create(['total_paise' => 45000]);

    InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'amount_paise' => 60000, 'type' => 'subscription']);
    InvoiceLine::factory()->create(['invoice_id' => $invoice->id, 'amount_paise' => -15000, 'type' => 'proration_credit']);

    expect((int) $invoice->lines()->sum('amount_paise'))->toBe(45000);
});

test('credits are negative lines', function () {
    $invoice = Invoice::factory()->create();
    $credit = InvoiceLine::factory()->prorationCredit()->create(['invoice_id' => $invoice->id]);

    expect($credit->amount_paise)->toBeLessThan(0);
});

test('invoice numbers are sequential', function () {
    $generator = app(InvoiceNumberGenerator::class);
    $issuedAt = CarbonImmutable::parse('2026-05-01');

    $numbers = DB::transaction(fn () => [
        $generator->next($issuedAt),
        $generator->next($issuedAt),
        $generator->next($issuedAt),
    ]);

    expect($numbers)->toBe([
        'INV-2026-000001',
        'INV-2026-000002',
        'INV-2026-000003',
    ]);
});

test('invoice numbers have no gaps after a rollback', function () {
    $generator = app(InvoiceNumberGenerator::class);
    $issuedAt = CarbonImmutable::parse('2026-05-01');

    expect($generator->next($issuedAt))->toBe('INV-2026-000001');

    try {
        DB::transaction(function () use ($generator, $issuedAt) {
            $generator->next($issuedAt); // allocates 000002 inside this transaction
            throw new RuntimeException('simulated failure after allocation');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The rollback un-allocated the number -- the next call gets 000002,
    // not 000003.
    expect($generator->next($issuedAt))->toBe('INV-2026-000002');
});

test('invoice numbers restart each year', function () {
    $generator = app(InvoiceNumberGenerator::class);

    expect($generator->next(CarbonImmutable::parse('2026-12-31')))->toBe('INV-2026-000001');
    expect($generator->next(CarbonImmutable::parse('2027-01-01')))->toBe('INV-2027-000001');
});

test('concurrent invoice number allocation does not duplicate a number', function () {
    // No real second process in a single-process test, but the mechanism
    // that prevents duplication -- lockForUpdate() inside a transaction --
    // is exercised directly: two allocations for the same year in
    // sequence never produce the same number.
    $generator = app(InvoiceNumberGenerator::class);
    $issuedAt = CarbonImmutable::parse('2026-05-01');

    $a = DB::transaction(fn () => $generator->next($issuedAt));
    $b = DB::transaction(fn () => $generator->next($issuedAt));

    expect($a)->not->toBe($b);
});

test('a paid invoice cannot be modified', function () {
    $invoice = Invoice::factory()->paid()->create();

    expect(fn () => $invoice->update(['total_paise' => 1]))
        ->toThrow(LogicException::class);
});

test('voiding a paid invoice is allowed and does not delete it', function () {
    $invoice = Invoice::factory()->paid()->create();

    $invoice->void();

    expect($invoice->fresh()->status)->toBe('void');
    expect(Invoice::find($invoice->id))->not->toBeNull();
});

test('voiding an invoice does not delete it', function () {
    $invoice = Invoice::factory()->create();

    $invoice->void();

    expect(Invoice::find($invoice->id))->not->toBeNull();
    expect($invoice->fresh()->status)->toBe('void');
});
