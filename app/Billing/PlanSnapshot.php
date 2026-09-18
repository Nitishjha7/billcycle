<?php

namespace App\Billing;

/**
 * A plain, database-free stand-in for a plan's price -- used by the
 * ProrationCalculator test suite so it never needs an Eloquent connection.
 * Production code passes a real Plan model instead, which implements the
 * same PlanPricing interface.
 */
final class PlanSnapshot implements PlanPricing
{
    public function __construct(
        private readonly int $pricePaise,
        private readonly ?string $identity = null,
    ) {}

    public function pricePaise(): int
    {
        return $this->pricePaise;
    }

    public function planIdentity(): ?string
    {
        return $this->identity;
    }
}
