<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PlanChange;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * php artisan billing:run. For each subscription whose current_period_end is
 * today or past, and whose status is active or past_due: create the invoice
 * for the next period, add the subscription line, advance the period, and
 * dispatch a payment attempt. See docs/TECHNICAL_SPEC.md #4.
 *
 * Idempotent by construction: UNIQUE (subscription_id, period_start) on
 * invoices means a second attempt at the same insert is rejected by the
 * database, not skipped by an application-level check that has a race
 * window between reading and writing.
 */
final class BillingRunner
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
        private readonly DunningService $dunning,
    ) {}

    /**
     * @return array{billed: int, already_billed: int, skipped: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $billed = 0;
        $alreadyBilled = 0;
        $skipped = 0;

        $due = Subscription::query()
            ->whereIn('status', ['active', 'past_due'])
            // current_period_end is a date column, but comparing it as a
            // plain string against just a date (no time part) makes
            // '2026-02-01 00:00:00' <= '2026-02-01' false lexically, which
            // silently skipped everything due *today*. Compare against the
            // end of today instead, so "today or past" means what it says.
            ->where('current_period_end', '<=', $now->endOfDay())
            ->get();

        foreach ($due as $subscription) {
            [$result, $invoice] = $this->billOne($subscription, $now);

            match ($result) {
                'billed' => $billed++,
                'already_billed' => $alreadyBilled++,
                'skipped' => $skipped++,
            };

            // The payment attempt happens after the billing transaction has
            // committed, not nested inside it -- a gateway failure must
            // never roll back the invoice that was just correctly created.
            if ($result === 'billed' && $invoice !== null) {
                $this->dunning->attempt($invoice, $now);
            }
        }

        return [
            'billed' => $billed,
            'already_billed' => $alreadyBilled,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{0: 'billed'|'already_billed'|'skipped', 1: ?Invoice}
     */
    private function billOne(Subscription $subscription, CarbonImmutable $now): array
    {
        try {
            $invoice = DB::transaction(function () use ($subscription, $now) {
                // Re-check status inside the transaction: a customer may
                // have cancelled or been suspended between the selection
                // query above and this row being processed.
                $subscription->refresh();

                if (! in_array($subscription->status, ['active', 'past_due'], true)) {
                    return null;
                }

                $periodStart = CarbonImmutable::parse($subscription->current_period_end);
                $periodEnd = $this->nextPeriodEnd($periodStart, $subscription->plan->interval);

                // A downgrade applied earlier in this cycle leaves a credit
                // that isn't owed to the customer until it lands on a real
                // invoice -- this is that invoice. lockForUpdate() prevents
                // the same credit being carried onto two invoices if this
                // ever runs concurrently for the same subscription.
                $pendingCredit = PlanChange::query()
                    ->where('subscription_id', $subscription->id)
                    ->where('net_paise', '<=', 0)
                    ->whereNull('applied_invoice_id')
                    ->lockForUpdate()
                    ->first();

                $subtotal = $subscription->plan->price_paise;
                $total = $subtotal - ($pendingCredit?->credit_paise ?? 0);

                $invoice = Invoice::create([
                    'subscription_id' => $subscription->id,
                    'number' => $this->numbers->next($now),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'subtotal_paise' => $subtotal,
                    'total_paise' => $total,
                    'status' => 'open',
                    'issued_at' => $now,
                ]);

                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'description' => $subscription->plan->name,
                    'amount_paise' => $subtotal,
                    'type' => 'subscription',
                ]);

                if ($pendingCredit !== null) {
                    InvoiceLine::create([
                        'invoice_id' => $invoice->id,
                        'description' => "Credit from plan change on {$pendingCredit->changed_at->toDateString()}",
                        'amount_paise' => -$pendingCredit->credit_paise,
                        'type' => 'proration_credit',
                    ]);

                    $pendingCredit->update(['applied_invoice_id' => $invoice->id]);
                }

                $subscription->update([
                    'current_period_start' => $periodStart,
                    'current_period_end' => $periodEnd,
                ]);

                return $invoice;
            });

            return $invoice === null ? ['skipped', null] : ['billed', $invoice];
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return ['already_billed', null];
            }

            throw $e;
        }
    }

    private function nextPeriodEnd(CarbonImmutable $periodStart, string $interval): CarbonImmutable
    {
        return $interval === 'yearly'
            ? $periodStart->addYear()
            : $periodStart->addMonth();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23505 is the Postgres unique_violation code. SQLite
        // (used in the fast unit/feature test suite) reports its own
        // "UNIQUE constraint failed" text instead of a SQLSTATE, so both
        // are checked -- the guarantee must hold on both engines the
        // project actually runs tests against.
        return $e->getCode() === '23505'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
