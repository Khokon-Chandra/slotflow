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

## 5 · Every AI task has a deterministic fallback

**Status** Accepted

**Context.** A portfolio project that needs an API key is a screenshot. A production feature that needs a third party to be up is a liability.

**Decision.** `AiClient` has two implementations. `AI_DRIVER=auto` picks Claude when a key exists and the heuristic otherwise, and the manager falls back on rate limit, budget exhaustion, timeout or error.

**Consequences.** The fallback is real work — a date parser, a keyword matcher, four templates — and it has to be maintained. In exchange: the demo runs from a clone, CI needs no secret and makes no network call, and an outage degrades the product instead of breaking it.

**What would change it.** Nothing. This one is load-bearing.

---

## 6 · The admin panel is a client of the public API

**Status** Accepted

**Considered.** Dedicated Inertia endpoints for the panel, and `/api/v1` for third parties.

**Rejected because** two APIs means two sets of bugs, and the public one gets tested least. The one your own product does not use is the one that rots.

**Decision.** Inertia controllers render initial page props; everything interactive afterwards calls `/api/v1` with the session cookie (`statefulApi()`).

**Consequences.** If a response shape is awkward for us, it is awkward for third parties, and we find out immediately rather than in a support ticket. Costs one round trip on first interaction.

**What would change it.** A page whose data needs cannot be expressed in the public API without contorting it. That would be a signal about the API, not about the page.

---

## 7 · Policies answer "who", enums answer "what"

**Status** Accepted

**Context.** `BookingPolicy::update()` originally refused terminal bookings, which felt tidy.

**It was wrong.** A client attempting an illegal transition got `403` — "you are not allowed" — when the truth was "that is not a thing". And it hid the useful part of the answer.

**Decision.** Policies check role, ownership and tenancy. `BookingStatus` owns the transition table. `BookingService::transition()` raises `422 invalid_booking_transition` with `context.allowed`.

**Consequences.** Two checks instead of one, and clients can tell an authorisation problem from a state problem. Caught by a test that expected 422 and got 403.

---

## 8 · One error envelope

**Status** Accepted

**Context.** Laravel returns `{message, errors}` for validation, `{message}` for a missing model, and something else for everything else. Clients write three parsers and get the third one wrong.

**Decision.** Every JSON error is `{ "error": { "code", "message", "context"? , "fields"? } }`. `code` is a stable identifier; changing one is a breaking change. Domain failures render themselves.

**Consequences.** Some framework-shaped code in `bootstrap/app.php`. In exchange, a client branches on one field, and a taken slot is a `409` with `slot_unavailable` rather than a 500. Locked in by [`ErrorEnvelopeTest`](../tests/Feature/Api/ErrorEnvelopeTest.php).

---

## 9 · Tests run against MySQL, not SQLite

**Status** Accepted

**Context.** SQLite in memory is much faster and the default in a new Laravel app.

**Rejected because** SQLite has no `SELECT … FOR UPDATE`. The double-booking guard is the most important behaviour in this application, and on SQLite the test would pass without testing anything. A suite that runs on a database missing the feature under test passes for the wrong reason.

**Decision.** MySQL for the whole suite, in CI too.

**Consequences.** CI needs a service container and the suite takes 4 seconds instead of 1. Both are cheap. It also means every migration, index and constraint is exercised on the engine that will run in production.

---

## 10 · The buffer is stored, not derived

**Status** Accepted

**Context.** A booking blocks `duration + buffer`, but the customer is told `duration`.

**Considered.** Store `ends_at` only and add the buffer at query time.

**Rejected because** the overlap check then cannot be a single SQL predicate: it needs the service's current buffer joined in, and every comparison becomes PHP-side reconciliation the database cannot enforce. The one check that must be exactly right becomes the one the database cannot help with.

**Decision.** Store both `ends_at` and `blocks_until`, snapshotting the buffer at booking time.

**Consequences.** One denormalised column. Changing a service's buffer does not retroactively shift existing appointments — which is correct behaviour, not a limitation.

---

## 11 · `Model::shouldBeStrict()` in local

**Status** Accepted

**Decision.** Lazy loading, silently discarded attributes and missing attributes are exceptions in development.

**Consequences.** It caught two genuine N+1s while this was being built — one in `ServiceResource` reading `$service->tenant->currency` per row, one in the admin diary. Both were invisible on six seeded services and would have been fatal at fifty thousand bookings.

It also forces honesty about eager loading: `with()` calls are mandatory rather than aspirational. The one place strictness is disabled is `demo:bench`, which deliberately measures the N+1 it prevents — 5 queries against 101 on the same page.

---

## 12 · The risk weights are illustrative, and say so

**Status** Accepted, with a stated caveat

**Context.** The scorer assigns points per factor: prior no-show rate, lead time, time of day, whether a deposit is held, whether a phone number exists.

**These are not fitted to data.** They were chosen to be explainable, and they are documented as such in the code, in the admin UI and in the README.

**Why not fit them.** There is no data to fit them to — the history is seeded and invented. A model trained on invented data would be a more impressive-looking lie.

**Consequences.** The feature is honest about what it is: a transparent heuristic whose arithmetic ships with the answer, so the owner can disagree with it. That is the only way they will ever come to rely on it.

**What would change it.** Real history. On a live deployment these weights are the first thing you would replace — logistic regression over a year of outcomes, with the factor breakdown kept, because the explanation is most of the value.
