<?php

namespace App\Billing;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Pure function: no database access, no now(), no side effects. The change
 * date is passed in rather than read from the clock, so this is trivially
 * testable and the caller owns the timing decision.
 *
 * Takes PlanPricing rather than the Eloquent Plan model directly, so this
 * class and its test suite never touch a database connection -- see
 * docs/BUILD_PLAN.md Phase 2: "No database. No UI. No routes."
 *
 * Called twice for a single plan change -- once from the preview endpoint,
 * once from the apply path inside a transaction -- and both calls must
 * produce the same output. See docs/TECHNICAL_SPEC.md #3.
 */
final class ProrationCalculator
{
    public static function calculate(
        PlanPricing $oldPlan,
        PlanPricing $newPlan,
        CarbonImmutable $changeDate,
        CarbonImmutable $cycleStart,
        CarbonImmutable $cycleEnd,
    ): ProrationResult {
        if (self::samePlan($oldPlan, $newPlan)) {
            throw new InvalidArgumentException('Cannot prorate a plan change to the same plan.');
        }

        $totalDays = $cycleStart->diffInDays($cycleEnd);
        $remainingDays = $changeDate->diffInDays($cycleEnd);

        if ($totalDays === 0) {
            // A zero-length cycle has no daily rate to speak of. This should
            // not occur in practice (cycleEnd is always after cycleStart),
            // but dividing by zero must never happen here.
            return new ProrationResult(creditPaise: 0, chargePaise: 0, netPaise: 0);
        }

        // Credit rounds up, charge rounds down: the remainder favours the
        // customer. See docs/TECHNICAL_SPEC.md #1.
        $creditPaise = (int) ceil($oldPlan->pricePaise() * $remainingDays / $totalDays);
        $chargePaise = (int) floor($newPlan->pricePaise() * $remainingDays / $totalDays);
        $netPaise = $chargePaise - $creditPaise;

        return new ProrationResult(
            creditPaise: $creditPaise,
            chargePaise: $chargePaise,
            netPaise: $netPaise,
        );
    }

    /**
     * Identity, not equal price. A plan with no identity (unsaved, or a
     * bare snapshot) is never "the same plan" as another unless it is
     * literally the same object -- two distinct in-memory plans that merely
     * share a price must not be rejected as a no-op change.
     */
    private static function samePlan(PlanPricing $a, PlanPricing $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $identityA = $a->planIdentity();
        $identityB = $b->planIdentity();

        return $identityA !== null && $identityB !== null && $identityA === $identityB;
    }
}
