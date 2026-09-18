<?php

use App\Billing\PlanSnapshot;
use App\Billing\ProrationCalculator;
use Carbon\CarbonImmutable;

/**
 * Pure input to output. No database, no clock -- these run in milliseconds.
 * See docs/TECHNICAL_SPEC.md #3 and docs/TEST_PLAN.md #1.
 */
function plan(int $pricePaise): PlanSnapshot
{
    // Interval plays no part in the calculation itself -- totalDays comes
    // from the actual cycle dates passed in, not from the plan's interval
    // field. A "yearly plan" test simply passes a 365-day cycle.
    return new PlanSnapshot($pricePaise);
}

function d(string $value): CarbonImmutable
{
    return CarbonImmutable::parse($value);
}

// --- Basic arithmetic -------------------------------------------------

test('upgrade mid cycle credits unused time and charges the new plan', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(50000),     // Rs 500
        newPlan: plan(120000),    // Rs 1200
        changeDate: d('2026-09-16'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    // Sep 16 -> Oct 1 is 15 remaining days of a 30-day cycle.
    expect($r->creditPaise)->toBe(25000);   // 15/30 of Rs 500
    expect($r->chargePaise)->toBe(60000);   // 15/30 of Rs 1200
    expect($r->netPaise)->toBe(35000);      // Rs 350 due now
});

test('downgrade produces a negative net', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(120000),
        newPlan: plan(50000),
        changeDate: d('2026-09-15'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->netPaise)->toBeLessThan(0);
});

test('net is charge minus credit always', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(73000),
        newPlan: plan(159000),
        changeDate: d('2026-09-11'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->netPaise)->toBe($r->chargePaise - $r->creditPaise);
});

// --- Cycle length -------------------------------------------------------

test('february uses 28 days not 30', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(28000),
        newPlan: plan(56000),
        changeDate: d('2027-02-15'),
        cycleStart: d('2027-02-01'),
        cycleEnd: d('2027-03-01'),
    );

    // 14 remaining days (Feb 15 -> Mar 1) of a 28-day February -- not of 30.
    expect($r->creditPaise)->toBe((int) ceil(28000 * 14 / 28));
});

test('march uses 31 days', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(31000),
        newPlan: plan(62000),
        changeDate: d('2027-03-15'),
        cycleStart: d('2027-03-01'),
        cycleEnd: d('2027-04-01'),
    );

    expect($r->creditPaise)->toBe((int) ceil(31000 * 17 / 31));
});

test('leap year february uses 29 days', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(29000),
        newPlan: plan(58000),
        changeDate: d('2028-02-15'),
        cycleStart: d('2028-02-01'),
        cycleEnd: d('2028-03-01'),
    );

    // 15 remaining days (Feb 15 -> Mar 1) of a 29-day leap-year February.
    expect($r->creditPaise)->toBe((int) ceil(29000 * 15 / 29));
});

test('yearly plan prorates over 365 days', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(600000),
        newPlan: plan(1200000),
        changeDate: d('2026-07-02'),
        cycleStart: d('2026-01-01'),
        cycleEnd: d('2027-01-01'),
    );

    $totalDays = (int) d('2026-01-01')->diffInDays(d('2027-01-01'));
    $remainingDays = (int) d('2026-07-02')->diffInDays(d('2027-01-01'));

    expect($totalDays)->toBe(365);
    expect($r->creditPaise)->toBe((int) ceil(600000 * $remainingDays / $totalDays));
});

// --- Boundaries -----------------------------------------------------------

test('change on cycle start day credits the whole period', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(50000),
        newPlan: plan(120000),
        changeDate: d('2026-09-01'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->creditPaise)->toBe(50000);
    expect($r->chargePaise)->toBe(120000);
});

test('change on cycle end day produces zero and zero', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(50000),
        newPlan: plan(120000),
        changeDate: d('2026-10-01'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->creditPaise)->toBe(0);
    expect($r->chargePaise)->toBe(0);
    expect($r->netPaise)->toBe(0);
});

test('change one day before end credits one day', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(30000),
        newPlan: plan(60000),
        changeDate: d('2026-09-30'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->creditPaise)->toBe((int) ceil(30000 * 1 / 30));
});

// --- Rounding ---------------------------------------------------------

test('remainder favours the customer', function () {
    // Two distinct plan instances of the same price that does not divide
    // evenly over 10 remaining of 30 days -- not a same-plan change, since
    // ProrationCalculator compares plan identity, not price.
    $r = ProrationCalculator::calculate(
        oldPlan: plan(100000),
        newPlan: plan(100000),
        changeDate: d('2026-09-21'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    $exact = 100000 * 10 / 30; // 33333.33...

    // Credit rounds up, charge rounds down.
    expect($r->creditPaise)->toBe((int) ceil($exact));
    expect($r->chargePaise)->toBe((int) floor($exact));
    expect($r->creditPaise)->toBeGreaterThanOrEqual($r->chargePaise);
});

test('rounding never loses more than one paisa', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(100003),
        newPlan: plan(100003),
        changeDate: d('2026-09-21'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    // Same price on both sides: any difference between credit and charge is
    // pure rounding, and it cannot exceed one paisa.
    expect(abs($r->creditPaise - $r->chargePaise))->toBeLessThanOrEqual(1);
});

test('no calculation ever returns a float', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(100003),
        newPlan: plan(259999),
        changeDate: d('2026-09-17'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->creditPaise)->toBeInt();
    expect($r->chargePaise)->toBeInt();
    expect($r->netPaise)->toBeInt();
});

// --- Plan shapes --------------------------------------------------------

test('same plan change is rejected', function () {
    $p = plan(50000);

    expect(fn () => ProrationCalculator::calculate(
        oldPlan: $p,
        newPlan: $p,
        changeDate: d('2026-09-15'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    ))->toThrow(InvalidArgumentException::class);
});

test('free plan to paid plan charges without credit', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(0),
        newPlan: plan(120000),
        changeDate: d('2026-09-15'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->creditPaise)->toBe(0);
    expect($r->chargePaise)->toBeGreaterThan(0);
});

test('paid plan to free plan credits without charge', function () {
    $r = ProrationCalculator::calculate(
        oldPlan: plan(120000),
        newPlan: plan(0),
        changeDate: d('2026-09-15'),
        cycleStart: d('2026-09-01'),
        cycleEnd: d('2026-10-01'),
    );

    expect($r->creditPaise)->toBeGreaterThan(0);
    expect($r->chargePaise)->toBe(0);
});

// --- Known failing test --------------------------------------------------

test('two plan changes on the same day do not double credit', function () {
    // Basic -> Pro -> Enterprise, all on the 15th.
    // The second change must prorate from the 15th, not re-credit
    // the full remaining period of a plan already credited once.
})->todo('Known limitation - see INTERVIEW_NOTES.md section on limitations');
