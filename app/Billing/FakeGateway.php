<?php

namespace App\Billing;

use Illuminate\Support\Str;

/**
 * Outcome driven entirely by configuration, so a test can say "this
 * reference declines twice then succeeds" in one line instead of hoping a
 * real gateway produces the failure it needs. See docs/TECHNICAL_SPEC.md #6.
 *
 * State is keyed by the charge reference passed in (in practice, the
 * invoice number or id) so different subscriptions/invoices in the same
 * test can be configured independently.
 */
final class FakeGateway implements PaymentGateway
{
    /** @var array<string, int> reference => number of remaining forced failures */
    private array $failUntilSuccessCounts = [];

    /** @var array<string, string> reference => failure code to always return */
    private array $alwaysFailing = [];

    private bool $alwaysFailAll = false;

    private string $defaultFailureCode = 'card_declined';

    public function charge(int $amountPaise, string $reference): GatewayResult
    {
        if ($this->alwaysFailAll) {
            return GatewayResult::failure($this->defaultFailureCode);
        }

        if (isset($this->alwaysFailing[$reference])) {
            return GatewayResult::failure($this->alwaysFailing[$reference]);
        }

        if (($this->failUntilSuccessCounts[$reference] ?? 0) > 0) {
            $this->failUntilSuccessCounts[$reference]--;

            return GatewayResult::failure($this->defaultFailureCode);
        }

        return GatewayResult::success('fake_'.Str::uuid());
    }

    /**
     * Every charge attempt fails, regardless of reference, until reset.
     * Used for "drive this all the way to suspended" tests.
     */
    public function alwaysFails(string $failureCode = 'card_declined'): static
    {
        $this->alwaysFailAll = true;
        $this->defaultFailureCode = $failureCode;

        return $this;
    }

    /**
     * A specific reference always fails with the given code -- other
     * references are unaffected.
     */
    public function failReference(string $reference, string $failureCode = 'card_declined'): static
    {
        $this->alwaysFailing[$reference] = $failureCode;

        return $this;
    }

    /**
     * A specific reference fails its next $count attempts, then succeeds.
     */
    public function failNextAttempts(string $reference, int $count, string $failureCode = 'card_declined'): static
    {
        $this->failUntilSuccessCounts[$reference] = $count;
        $this->defaultFailureCode = $failureCode;

        return $this;
    }

    public function reset(): static
    {
        $this->failUntilSuccessCounts = [];
        $this->alwaysFailing = [];
        $this->alwaysFailAll = false;

        return $this;
    }
}
