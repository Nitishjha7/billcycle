<?php

namespace App\Billing;

/**
 * The outcome of a single charge attempt against a PaymentGateway.
 */
final class GatewayResult
{
    private function __construct(
        public readonly bool $succeeded,
        public readonly ?string $reference,
        public readonly ?string $failureCode,
    ) {}

    public static function success(string $reference): self
    {
        return new self(succeeded: true, reference: $reference, failureCode: null);
    }

    public static function failure(string $failureCode): self
    {
        return new self(succeeded: false, reference: null, failureCode: $failureCode);
    }
}
