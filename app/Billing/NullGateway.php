<?php

namespace App\Billing;

use Illuminate\Support\Str;

/**
 * Always succeeds. Used in tests that are not about payment failure, so
 * they don't have to configure a FakeGateway just to get out of the way.
 * See docs/TECHNICAL_SPEC.md #6.
 */
final class NullGateway implements PaymentGateway
{
    public function charge(int $amountPaise, string $reference): GatewayResult
    {
        return GatewayResult::success('null_'.Str::uuid());
    }
}
