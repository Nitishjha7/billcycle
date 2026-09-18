<?php

namespace App\Billing;

/**
 * The output of a proration calculation. A plain value object -- no
 * behaviour, no persistence. See docs/TECHNICAL_SPEC.md #3.
 */
final class ProrationResult
{
    public function __construct(
        public readonly int $creditPaise,
        public readonly int $chargePaise,
        public readonly int $netPaise,
    ) {}
}
