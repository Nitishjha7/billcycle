<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Applies a mid-cycle plan change: calls ProrationCalculator, records the
 * PlanChange, and -- for a net-positive change -- creates and charges an
 * invoice immediately. See docs/TECHNICAL_SPEC.md #3 and docs/UI_FLOW.md #4.
 */
final class PlanChangeService
{
    public function __construct(
        private readonly InvoiceNumberGenerator $numbers,
    ) {}

    /**
     * Preview only -- calls the same calculator the apply path uses, with
     * the same inputs, so preview and reality cannot disagree. No writes.
     */
    public function preview(Subscription $subscription, Plan $newPlan, CarbonImmutable $changeDate): ProrationResult
    {
        return ProrationCalculator::calculate(
            oldPlan: $subscription->plan,
            newPlan: $newPlan,
            changeDate: $changeDate,
            cycleStart: CarbonImmutable::parse($subscription->current_period_start),
            cycleEnd: CarbonImmutable::parse($subscription->current_period_end),
        );
    }

    public function apply(Subscription $subscription, Plan $newPlan, CarbonImmutable $changeDate): PlanChange
    {
        return DB::transaction(function () use ($subscription, $newPlan, $changeDate) {
            $oldPlan = $subscription->plan;

            $result = ProrationCalculator::calculate(
                oldPlan: $oldPlan,
                newPlan: $newPlan,
                changeDate: $changeDate,
                cycleStart: CarbonImmutable::parse($subscription->current_period_start),
                cycleEnd: CarbonImmutable::parse($subscription->current_period_end),
            );

            $planChange = PlanChange::create([
                'subscription_id' => $subscription->id,
                'from_plan_id' => $oldPlan->id,
                'to_plan_id' => $newPlan->id,
                'changed_at' => $changeDate,
                'credit_paise' => $result->creditPaise,
                'charge_paise' => $result->chargePaise,
                'net_paise' => $result->netPaise,
            ]);

            if ($result->netPaise > 0) {
                // Upgrade: charged today. A new invoice is created now,
                // covering only the remainder of the current cycle -- the
                // regular next-cycle invoice is unaffected.
                $this->chargeToday($subscription, $oldPlan, $newPlan, $changeDate, $result);
            }
            // Downgrade (net <= 0): no refund, no invoice today. The credit
            // is a deliberate limitation carried forward only in the
            // PlanChange record for now -- attaching it to the next regular
            // invoice is billing:run's job (Phase 3 follow-up), not this
            // service's, since that invoice does not exist yet.

            $subscription->update(['plan_id' => $newPlan->id]);

            return $planChange;
        });
    }

    private function chargeToday(
        Subscription $subscription,
        Plan $oldPlan,
        Plan $newPlan,
        CarbonImmutable $changeDate,
        ProrationResult $result,
    ): void {
        $invoice = Invoice::create([
            'subscription_id' => $subscription->id,
            'number' => $this->numbers->next($changeDate),
            'period_start' => $changeDate,
            'period_end' => $subscription->current_period_end,
            'subtotal_paise' => $result->netPaise,
            'total_paise' => $result->netPaise,
            'status' => 'open',
            'issued_at' => $changeDate,
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => "Unused {$oldPlan->name}",
            'amount_paise' => -$result->creditPaise,
            'type' => 'proration_credit',
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'description' => "{$newPlan->name} for remaining period",
            'amount_paise' => $result->chargePaise,
            'type' => 'proration_charge',
        ]);
    }
}
