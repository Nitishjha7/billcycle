<?php

namespace App\Billing;

use App\Models\Subscription;
use LogicException;

/**
 * Enforces the dunning state machine from docs/TECHNICAL_SPEC.md #5.
 * Illegal transitions throw -- they are a bug in the calling code, not a
 * no-op to be silently absorbed.
 *
 *                     invoice issued
 *                           |
 *                           v
 *                    +-------------+
 *                    |   ACTIVE    |<--------------+
 *                    +-------------+               |
 *                           |                      |
 *                   payment fails                  | payment
 *                           |                      | succeeds
 *                           v                      |
 *                    +-------------+               |
 *         +--------->|  PAST_DUE   |---------------+
 *         |          +-------------+
 *         |                 |
 *    retry fails    attempt 4 fails
 *    (attempts 1-3)         |
 *         |                 v
 *         |          +-------------+
 *         +----------|  SUSPENDED  |
 *                    +-------------+
 *                           |
 *                    payment succeeds
 *                           |
 *                           v
 *                        ACTIVE
 */
final class SubscriptionStateMachine
{
    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        'active' => ['past_due', 'cancelled'],
        'trialing' => ['active', 'cancelled'],
        'past_due' => ['past_due', 'suspended', 'active', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'cancelled' => [],
    ];

    public static function transition(Subscription $subscription, string $to): void
    {
        $from = $subscription->status;

        if ($from === $to) {
            return;
        }

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new LogicException("Illegal subscription status transition: {$from} -> {$to}.");
        }

        $subscription->update(['status' => $to]);
    }
}
