<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Drives a single invoice's payment attempt and, on failure, the retry
 * schedule and subscription status. See docs/TECHNICAL_SPEC.md #5.
 *
 * Retry schedule (fixed, widening intervals -- covers both an immediate
 * card_declined retry and an insufficient_funds retry that waits for
 * payday, without a per-failure-code branch):
 *
 *   attempt 1 (on invoice) -> fails -> retry at +1 day
 *   attempt 2 (+1 day)     -> fails -> retry at +3 days
 *   attempt 3 (+3 days)    -> fails -> retry at +5 days
 *   attempt 4 (+5 days)    -> fails -> suspend
 */
final class DunningService
{
    /** @var list<int> days to wait before each retry, indexed by (attempt_number - 1) */
    private const RETRY_INTERVALS_DAYS = [1, 3, 5];

    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    public function attempt(Invoice $invoice, CarbonImmutable $now): PaymentAttempt
    {
        return DB::transaction(function () use ($invoice, $now) {
            $subscription = $invoice->subscription()->lockForUpdate()->first();
            $attemptNumber = $invoice->attempts()->count() + 1;

            $result = $this->gateway->charge($invoice->total_paise, $invoice->number);

            if ($result->succeeded) {
                return $this->recordSuccess($invoice, $subscription, $attemptNumber, $now, $result);
            }

            return $this->recordFailure($invoice, $subscription, $attemptNumber, $now, $result);
        });
    }

    private function recordSuccess(
        Invoice $invoice,
        Subscription $subscription,
        int $attemptNumber,
        CarbonImmutable $now,
        GatewayResult $result,
    ): PaymentAttempt {
        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => $attemptNumber,
            'failure_code' => null,
            'attempted_at' => $now,
            'next_retry_at' => null,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount_paise' => $invoice->total_paise,
            'status' => 'succeeded',
            'gateway_reference' => $result->reference,
        ]);

        $invoice->update(['status' => 'paid']);

        // Success at any point resets everything: status to active, pending
        // retries cleared (there is nothing to clear here beyond not
        // scheduling one -- no separate retry-job table exists to purge),
        // attempt counter reset for the *next* invoice's cycle naturally,
        // since attempt_number is scoped per invoice.
        $wasSuspended = $subscription->status === 'suspended';
        SubscriptionStateMachine::transition($subscription, 'active');

        if ($wasSuspended) {
            // The suspended days are not billed: on reactivation the period
            // restarts from the payment date. See docs/TECHNICAL_SPEC.md #5.
            $subscription->update([
                'current_period_start' => $now,
                'current_period_end' => $this->nextPeriodEnd($now, $subscription->plan->interval),
            ]);
        }

        return $attempt;
    }

    private function recordFailure(
        Invoice $invoice,
        Subscription $subscription,
        int $attemptNumber,
        CarbonImmutable $now,
        GatewayResult $result,
    ): PaymentAttempt {
        $intervalDays = self::RETRY_INTERVALS_DAYS[$attemptNumber - 1] ?? null;
        $nextRetryAt = $intervalDays !== null ? $now->addDays($intervalDays) : null;

        $attempt = PaymentAttempt::create([
            'invoice_id' => $invoice->id,
            'attempt_number' => $attemptNumber,
            'failure_code' => $result->failureCode,
            'attempted_at' => $now,
            'next_retry_at' => $nextRetryAt,
        ]);

        Payment::create([
            'invoice_id' => $invoice->id,
            'amount_paise' => $invoice->total_paise,
            'status' => 'failed',
            'gateway_reference' => 'failed_'.$attempt->id,
        ]);

        if ($nextRetryAt === null) {
            // Attempt 4 failed: no more retries, suspend.
            SubscriptionStateMachine::transition($subscription, 'suspended');
        } else {
            SubscriptionStateMachine::transition($subscription, 'past_due');
        }

        return $attempt;
    }

    private function nextPeriodEnd(CarbonImmutable $periodStart, string $interval): CarbonImmutable
    {
        return $interval === 'yearly'
            ? $periodStart->addYear()
            : $periodStart->addMonth();
    }
}
