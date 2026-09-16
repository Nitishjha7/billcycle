# Decisions

A running log of every non-obvious decision, **written at the time it is made**.

## Why this file exists

An interviewer will not read the code. They will point at something and ask "why
is it like that?". This file is where that answer lives while it is still fresh.

Written as you go, it is an honest record of the reasoning. Written afterwards
from memory, it is a reconstruction — and it reads like one.

## How to write an entry

Three to six lines. Date, what was chosen, what was rejected, and **why**.

The "what was rejected" line matters most. A decision with no alternative
considered is not a decision, it is a default.

```markdown
## YYYY-MM-DD — Short title

What was chosen. What the alternative was. Why the alternative loses. What it
costs (every decision costs something).
```

Also log **bugs that surprised you**. Those are the strongest interview material
available, and they are forgotten within a week if not written down.

---

## 2026-09-16 — Why this project at all

Portfolio was entirely Python — Flask, FastAPI, agentic work. Applying for Laravel
roles with no Laravel project is a screening problem before it is anything else.

Billing chosen over a CRUD app because it has a real correctness problem at the
centre — proration arithmetic, idempotent jobs — and because queues, scheduled
jobs and server-rendered dashboards are what Laravel is actually used for.

Rejected: a helpdesk/ticketing system (too close to an existing portfolio project
in name), and an ERP-shaped "business OS" (scope always expands, and a half-built
ERP demos badly).

---

## Entries from here are written as the code is built

Things that will need an entry:

- Rounding direction, once the first uneven division shows up
- Whether the unique constraint alone is enough, or the job needs an advisory lock
- What happens to a subscription cancelled mid-dunning
- Any bug that took more than an hour — especially the ones that were not the
  obvious cause
