# Interview Notes

Pitch, trade-offs, limitations, and the questions that will come.

Read a question, answer it **without looking**. Wherever you stall is where an
interviewer will push.

---

## 1. The 30-second pitch

> "It is a subscription billing system — the part of a SaaS that charges people
> every month. I built it around the three things in billing that are not CRUD:
> proration when someone changes plan mid-cycle, dunning when a payment fails, and
> making sure the nightly billing job cannot charge someone twice if it runs
> twice.
>
> The proration calculator is a pure function with about thirty tests on it,
> because that is where the money errors hide. And the idempotency is a unique
> constraint in the schema rather than a check in application code, because a
> check has a race window."

Do not say "full-stack billing platform". Say what the three hard parts are. The
specificity is what makes it sound built rather than assembled.

---

## 2. Why this project

> "My portfolio was all Python — Flask, FastAPI, some agentic work. I was applying
> for Laravel roles with no Laravel project, which is a screening problem before
> it is anything else.
>
> I picked billing because it is the kind of thing Laravel is actually used for —
> queues, scheduled jobs, server-rendered dashboards — and because it has a real
> correctness problem at the centre of it rather than just CRUD with extra steps."

This answer is honest, and honesty about the portfolio gap reads better than a
manufactured origin story.

---

## 3. The three things worth defending

### Money as integer paise

**Q: Why not decimal, or just floats?**

Floats cannot represent `0.1` exactly, and proration divides a monthly price
across a month, takes a fraction of it, and does that on every plan change. Errors
of a paisa compound into a reconciliation mismatch nobody can explain six months
later.

`DECIMAL` is exact, but PDO returns it as a *string*, and a string is one
careless `(float)` cast from being wrong. An integer cannot be silently
downgraded. The column names carry the unit — `price_paise` — so a unit error is
visible where it is written, not three layers down.

**Q: Where does rounding happen?**

Once, at the end of a proration calculation, and the direction is a documented
decision: the remainder goes to the customer, credit up, charge down. Maximum cost
is one paisa per plan change. Rounding in our own favour is not defensible in a
support conversation for that amount.

### Idempotency in the schema

**Q: How do you stop double-billing?**

A unique constraint on `(subscription_id, period_start)`. The second run attempts
the same insert, the database rejects it, the job counts it as already billed.

**Q: Why not check first?**

`SELECT ... IF NOT EXISTS` then insert has a race window between the read and the
write. Two workers can both pass the check. The constraint has no window — it is
enforced at commit.

The broader point: this is the difference between *remembering* to be idempotent
and *being* idempotent. The guarantee survives an application-code bug.

### Proration as a pure function

**Q: Why not a method on the Subscription model?**

Three reasons. It is testable without a database, so the thirty edge-case tests
cost nothing to run. The preview screen and the apply path call it with identical
inputs and cannot disagree. And it takes the change date as a parameter instead of
reading `now()`, which is what makes twelve months of billing testable in
milliseconds.

---

## 4. Known limitations — say these before you are asked

### Same-day double plan change

A customer who changes plan twice on the same day gets credited twice for time
already credited by the first change. There is a test for it and **it fails on
purpose**, marked `todo` and named in the README.

The fix is to prorate from the last change rather than the cycle start, which
means threading change history into the calculator. Not done because it
complicates the pure function for a case that has not come up — a stated trade-off
rather than an oversight.

### No real payment gateway

`FakeGateway` with controllable outcomes, behind a `PaymentGateway` interface.

This is deliberate: a real gateway makes failure *hard to produce*, and failure is
the entire subject of the dunning logic. With a fake gateway a test says "declines
twice then succeeds" in one line. The interface is the seam a real integration
would slot into — that the seam exists is the part that matters.

### Downgrades credit rather than refund

Money owed back to a customer becomes a credit on the next invoice, not a refund.
A refund is a gateway operation with its own failure and reconciliation modes; a
credit is a line item. It is shown on the plan-change screen so the customer sees
the policy, not just the number.

### No tax, no multi-currency, no metering

Each would add surface area without adding depth. Named in the README as
non-goals with the reason, because an unexplained gap looks like ignorance and an
explained one looks like scope control.

---

## 5. Questions that will come

**Q: What happens if the billing job crashes halfway?**

Invoice creation and period advance are in the same transaction, so it rolls back
together. A re-run finds a clean state and bills normally. The failure mode that
actually matters — partially advancing the period without an invoice — cannot
happen.

**Q: Two workers run the job at the same time. What happens?**

One wins the unique constraint. The other catches the violation and no-ops. There
is a test that runs them concurrently.

**Q: A customer is suspended for six days and then pays. Do they owe for those days?**

No. Access was suspended so the period is not billed, and on reactivation the
period restarts from the payment date. It is a policy decision written down in the
spec — the alternative, billing for a period the customer could not use, is worse
and harder to defend.

**Q: Why do retries widen — 1, 3, 5 — instead of a fixed interval?**

An immediate retry on `card_declined` declines again. `insufficient_funds` might
clear on payday. Widening covers both without branching per failure code.

**Q: How do you know the whole thing is consistent, not just the units?**

An invariant test fires 500 random events — upgrades, downgrades, cancels,
payments, failures, clock advances — across twenty customers, then asserts every
customer's invoiced-minus-paid equals their outstanding balance. The randomness is
seeded so failures reproduce.

**Q: Why gapless invoice numbers? Auto-increment is simpler.**

Auto-increment leaves holes on rollback, and holes in an invoice sequence are an
audit problem. A sequence row locked with `SELECT ... FOR UPDATE` inside the same
transaction means a rollback un-allocates the number. It serialises invoice
creation on one row, which is irrelevant at this scale and buys a correctness
property.

**Q: Why server-rendered Blade instead of an API plus React?**

Because this is a back-office tool, and the rest of my portfolio is already API
plus React. Blade with server-rendered forms is what Laravel is for, and it made
the proration preview — a server-computed number shown before a commit — the
simplest possible thing rather than a state-sync problem.

**Q: What would you build next?**

Same-day double change first, since it is a known failing test. Then a real
gateway behind the existing interface. Then usage-based billing, which is a
genuinely different model rather than more of the same.

---

## 6. If they ask about AI assistance

Answer plainly. Everyone uses it; what matters is whether you understand what
came out.

> "I used it the way I use documentation and Stack Overflow — to move faster. The
> decisions are mine and they are written down in DECISIONS.md as I made them:
> why integer paise, why the constraint instead of a check, which rounding
> direction and what it costs. If you point at any line in the proration
> calculator I can tell you why it is that way and what breaks if you change it."

Then offer to do exactly that. The offer is the proof.

---

## 7. The one-line summary

> "The interesting part is not that it bills people. It is that it bills them
> **exactly once**, and gets the arithmetic right on the day they change plans."
