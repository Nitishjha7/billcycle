<?php

namespace App\Billing;

/**
 * The only shape ProrationCalculator needs from a "plan" -- price and
 * identity. Kept as an interface, not the Eloquent Plan model directly, so
 * the calculator and its tests never touch a database connection. Plan
 * implements this; tests can use the lightweight PlanSnapshot instead.
 */
interface PlanPricing
{
    public function pricePaise(): int;

    /**
     * A value that uniquely identifies this plan for the purpose of
     * detecting a same-plan "change". Persisted plans use their primary
     * key; a plan with no identity (not yet persisted) has none, and two
     * such plans are never considered the same one merely for sharing a
     * price.
     */
    public function planIdentity(): ?string;
}
