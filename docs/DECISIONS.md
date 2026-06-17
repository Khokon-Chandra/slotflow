# Decision records

What was chosen, what was rejected, and what would change the answer.

The last column is the point. A decision without a stated reversal condition is a preference wearing a lab coat.

---

## 1 · Prevent double booking with a transaction and a row lock

**Status** Accepted

**Context.** Two customers can pass the "is this slot free?" check before either writes. Availability caching widens the window; it does not create it.

**Considered**

| Option | Why not |
|---|---|
| Optimistic check before insert | The bug itself. Two readers both see free |
| A unique index on `(staff_id, starts_at)` | Only catches *identical* starts. An overlapping booking at 09:30 slips straight through |
| InnoDB gap locks over the bookings range | Works, but the guarantee depends on the isolation level and on whether the range matched rows. Hard to reason about, harder to test |
| Application-level lock (Redis) | Adds a second system to the correctness path. If Redis is down, is the app down or unsafe? Neither answer is good |
| **A row lock on the staff member, inside a transaction** | **Chosen** |

**Decision.** `SELECT … FOR UPDATE` on the `staff` row, then re-check overlap against committed data, then insert — all in one transaction, with three deadlock retries.

**Consequences.** Writes to one person's diary serialise; writes to different people stay parallel. The lock is on a row that always exists, so the behaviour does not depend on whether anything matched. It is boring, portable and obvious.

**What would change it.** A single staff member taking enough concurrent bookings for the serialisation to bite. Nobody has that problem: a chair does one appointment at a time. If a future "resource" — a room used by several staff at once — appears, the lock moves to the resource.

---

## 2 · Compute availability instead of storing slots

**Status** Accepted

**Context.** The obvious schema is a row per bookable fifteen minutes.

**Rejected because** every change needs a backfill: hours, service lengths, new staff, daylight saving. The day someone forgets one, the system sells time that does not exist.

**Decision.** Store weekly rules, time off and bookings. Compute `free = hours − time off − bookings` at request time, cache for 60 seconds behind a version counter.

**Consequences.** Correct by construction — there is no derived state to drift. Costs CPU per request, mitigated by caching and a hard 31-day range cap. Measured: 16 ms for 7 days cold, 0.8 ms warm, and **the query count does not grow with the range**.

**What would change it.** A tenant with hundreds of staff, or a range cap that has to grow past a month. Then a materialised view refreshed on write — but only once the cache hit rate is measured and found wanting, not before.

---

## 3 · Multi-tenancy: shared schema, scoped column

**Status** Accepted

**Considered**

| Option | Trade-off |
|---|---|
| Database per tenant | Strongest isolation. Migrations across N databases, connection juggling, cross-tenant reporting is a nightmare |
| Schema per tenant | Same problems, slightly cheaper |
| **Shared schema + `tenant_id`** | **Chosen** — one migration path, one connection, cheap reporting. Isolation is now the application's job |

**Decision.** `tenant_id` on every business table, a global scope on read, auto-fill on write, tenant-scoped `exists` rules in validation, and one explicit escape hatch — `withoutTenantScope()`.

**Consequences.** A forgotten `where` clause becomes a data leak, so the filter has to be the default rather than a habit. The escape hatch is greppable: four call sites, each a place to look twice. Tested against two fully-seeded workspaces, because a leak test against an empty second tenant tests nothing.

**What would change it.** A customer with a regulatory requirement for physical separation, or one tenant large enough to need its own hardware. Both are real reasons; "it feels safer" is not.

---

## 4 · The model never decides

**Status** Accepted

**Context.** Four features use a language model. Each is a place where an autonomous decision would be easy to build and expensive to be wrong about.

**Decision.** The model narrows searches and writes prose. It cannot write to the database, and it cannot produce a number.

- **Booking assistant** — returns a service id, a date window and a part of the day. The slots come from the availability engine; the booking goes through the same validation as any other.
- **Risk** — the score is deterministic PHP with a test per factor. The model writes two sentences explaining it, and is told not to argue with it.
- **Briefing** — every figure is computed and handed over.
- **Copy** — lands in a form field the owner edits.

**Consequences.** Ceilings on how impressive the features can be. Also ceilings on how wrong they can be. Every AI response carries an `ai` object naming the driver, and the UI renders model-written and template-written text differently.

**What would change it.** Nothing about model quality. This is not a hedge against models being bad at things — it is about which decisions should be reproducible and auditable. Charging someone a deposit is one of them.

---
