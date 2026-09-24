<?php

namespace App\Billing;

/**
 * The seam a real payment provider would slot into. Only FakeGateway and
 * NullGateway exist -- no real gateway is integrated, on purpose. See
 * docs/TECHNICAL_SPEC.md #6.
 */
interface PaymentGateway
{
    public function charge(int $amountPaise, string $reference): GatewayResult;
}
