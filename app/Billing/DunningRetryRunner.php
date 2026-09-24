<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use Carbon\CarbonImmutable;

/**
 * Finds invoices due for another payment attempt and re-attempts them via
 * DunningService. This is what the scheduler fires between billing:run runs
 * -- see docs/TECHNICAL_SPEC.md #5. Two cases count as due:
 *
 *   1. A scheduled retry (attempts 2-4): the most recent attempt on the
 *      invoice recorded a next_retry_at that has now passed.
 *   2. A suspended subscription's open invoice: attempt 4 already exhausted
 *      the schedule (no next_retry_at), but suspension is not cancellation
 *      -- "a single successful payment" must still be able to revive it, so
 *      these are retried on every run rather than left permanently stuck.
 */
final class DunningRetryRunner
{
    public function __construct(
        private readonly DunningService $dunning,
    ) {}

    /**
     * @return int number of retries attempted
     */
    public function run(CarbonImmutable $now): int
    {
        $scheduledInvoiceIds = $this->invoicesWithADueScheduledRetry($now);
        $suspendedInvoiceIds = $this->openInvoicesOfSuspendedSubscriptions();

        $due = Invoice::query()
            ->where('status', 'open')
            ->where(function ($query) use ($scheduledInvoiceIds, $suspendedInvoiceIds) {
                $query->whereIn('id', $scheduledInvoiceIds)
                    ->orWhereIn('id', $suspendedInvoiceIds);
            })
            ->get();

        foreach ($due as $invoice) {
            $this->dunning->attempt($invoice, $now);
        }

        return $due->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function invoicesWithADueScheduledRetry(CarbonImmutable $now)
    {
        // Only the most recent attempt per invoice carries a live
        // next_retry_at that matters -- earlier attempts' next_retry_at
        // values are historical. UUID primary keys aren't chronologically
        // sortable, so "most recent" is found by attempted_at, not id.
        $lastAttemptedAtByInvoice = PaymentAttempt::query()
            ->select('invoice_id')
            ->selectRaw('max(attempted_at) as last_attempted_at')
            ->groupBy('invoice_id')
            ->get()
            ->pluck('last_attempted_at', 'invoice_id');

        return PaymentAttempt::query()
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $now)
            ->get()
            ->filter(fn (PaymentAttempt $attempt) => $lastAttemptedAtByInvoice->get($attempt->invoice_id) !== null
                && $attempt->attempted_at->equalTo($lastAttemptedAtByInvoice->get($attempt->invoice_id)))
            ->pluck('invoice_id')
            ->unique();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function openInvoicesOfSuspendedSubscriptions()
    {
        return Invoice::query()
            ->where('status', 'open')
            ->whereHas('subscription', fn ($query) => $query->where('status', 'suspended'))
            ->pluck('id');
    }
}
