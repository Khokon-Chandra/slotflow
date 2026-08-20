<div align="center">

# SlotFlow

**Appointment booking for small service businesses — with an API you can actually build against.**

Laravel 13 · PHP 8.4 · MySQL 8 · Vue 3 + Inertia 2 · Tailwind CSS 4 · Claude

[Quick start](#quick-start) · [The four decisions worth reading](#the-four-decisions-worth-reading) · [API](docs/API.md) · [Architecture](docs/ARCHITECTURE.md) · [AI design](docs/AI.md)

</div>

---

## The problem

A salon, a physio practice, a tutor. Three chairs, no IT department. They lose money in three specific ways:

| What happens | What it costs |
|---|---|
| Two customers booked into the same slot | One of them is turned away at the door |
| Someone doesn't turn up | The slot is dead — and nobody saw it coming |
| A customer gives up on the calendar widget | The booking never happens at all |

SlotFlow is a working answer to all three. It is a portfolio project, not a product — the studio in it is invented — but nothing in it is faked: the concurrency guard is tested by forking real processes, the availability engine handles daylight saving, and the AI features degrade to a deterministic fallback rather than falling over.

## Quick start

```bash
git clone <this-repo> slotflow && cd slotflow

composer install && npm install
cp .env.example .env && php artisan key:generate

docker compose up -d          # MySQL, Redis, Mailpit — or use your own MySQL
php artisan demo:seed --fresh # schema + 570 bookings + risk assessments

php artisan serve             # http://localhost:8000
npm run dev                   # in a second terminal
```

**No API key needed.** Every AI feature has a deterministic fallback, so the demo, the test suite and CI all run without one. The same features get noticeably better with a key, and there are two ways to add one:

- `ANTHROPIC_API_KEY` in `.env` — the platform credential, used by every workspace
- **Admin → AI providers** — the workspace's own: Anthropic, OpenAI, DeepSeek, or any endpoint that speaks OpenAI Chat Completions. Verified against the provider before it is stored, encrypted at rest, never returned by any endpoint

The admin panel labels which mode produced what you are reading, and says which key paid for it.

| | |
|---|---|
| Booking page | <http://localhost:8000> |
| Admin panel | <http://localhost:8000/admin> |
| API reference | <http://localhost:8000/docs/api> (generated from the code) |
| Postman | [`postman/`](postman/) — 29 requests, log in once and the token is stored for you |

<!-- Screenshots: capture the six shots listed in docs/img/README.md, drop them
     in docs/img/, and delete these comment markers to show them.

<div align="center">

| | |
|---|---|
| ![Booking assistant](docs/img/booking-assistant.png) | ![Dashboard](docs/img/dashboard.png) |
| *Plain English in, real slots out* | *The morning briefing and today's diary* |
| ![Risk breakdown](docs/img/risk-detail.png) | ![AI usage](docs/img/ai-usage.png) |
| *A score you can argue with* | *What the AI features cost* |

</div>

-->

Demo accounts, password `password` for all three:

| | | |
|---|---|---|
| `owner@slotflow.test` | Owner | full admin |
| `maya@slotflow.test` | Staff | sees only her own diary |
| `customer@slotflow.test` | Customer | booking history |

## The four decisions worth reading

Anyone can list features. These are the four places where the obvious approach is wrong, and what this codebase does instead.

### 1. Double booking is prevented in the database, not in application code

"Check the slot is free, then insert" is a read followed by a write, and two requests can pass the read before either does the write. Both see a free slot, both insert, and a customer arrives to find someone else in the chair. Caching availability makes the window wider, not narrower.

The fix is to make the check and the write one atomic step. [`BookingService::create()`](app/Domain/Booking/BookingService.php) opens a transaction, takes a row lock on the **staff member** — the resource actually being contended, so bookings for different people still run in parallel — re-checks for an overlap inside the lock, and only then inserts.

Locking the parent row rather than relying on InnoDB gap locks is deliberate: gap-lock behaviour depends on the isolation level and on whether the range matched any rows, which makes the guarantee hard to reason about and harder to test. A lock on a row that always exists is boring, portable and obvious.

**And it is tested by forking four real processes at the same slot:**

```
✓ it rejects a second booking for the same slot
✓ it rejects a booking that merely overlaps an existing one
✓ it allows a booking that starts exactly when the previous one ends
✓ it takes an exclusive row lock on the staff member
✓ it lets exactly one of several simultaneous requests win the slot
```

That last test is only worth having if it fails when the guard is missing. Remove the `lockForUpdate()` line and it does:

```
Expected exactly one winner, got 4. Outcomes: won, won, won, won
```

Four processes, four bookings, one chair. → [`tests/Concurrency/ConcurrentBookingTest.php`](tests/Concurrency/ConcurrentBookingTest.php)

### 2. Availability is computed from rules, never stored as slots

The naive schema is a `slots` table with a row per bookable fifteen minutes. It breaks the first time a staff member changes their hours, a service length changes, or the clocks go forward — and the day someone forgets a backfill, the system quietly sells time that does not exist.

Here there is no `slots` table. Free time is computed at request time:

```
free = working hours − time off − existing bookings (including their buffers)
```

Working hours are weekly rules in **the staff member's own timezone**; time off and bookings are absolute UTC instants. Every comparison is half-open — `[09:00, 10:00)` and `[10:00, 11:00)` do not overlap — which is what makes back-to-back appointments bookable.

The seeded studio has a trichologist in `Asia/Kolkata` working for a business in `Europe/Vienna`, because a timezone bug that only appears across zones is a timezone bug you will ship. There are tests for both daylight-saving transitions: the spring window really is an hour shorter, and the autumn one an hour longer.

→ [`docs/AVAILABILITY.md`](docs/AVAILABILITY.md) · [`app/Domain/Availability/`](app/Domain/Availability/)

### 3. The AI never decides anything

Four features use a language model. None of them can write to the database, and none of them produce a number.

| Feature | What the model does | What it cannot do |
|---|---|---|
| **Booking assistant** — "a haircut next Tuesday afternoon" | Narrows a search: a service id, a date window, a part of the day | Book anything. Slots come from the availability engine; the customer chooses |
| **No-show risk** | Writes two sentences explaining a score | Compute the score. That is deterministic PHP with a test per factor |
| **Daily briefing** | Decides which of today's figures matter, and says so in English | Do the arithmetic. Every number is computed and handed to it |
| **Service descriptions** | Drafts copy for a service | Save it. It lands in a form field the owner edits |

The risk feature is the clearest case. No-shows cost real money, and the responses — asking for a deposit, phoning someone the day before — affect real customers. A number that drives that has to be reproducible, auditable and testable, and a model asked to "rate this booking 0-100" is none of those: the same input can score 40 today and 55 tomorrow, and nobody can say why. So the arithmetic is ordinary PHP, the full factor breakdown ships with the score, and the model's job is the part it is genuinely good at — the sentence a receptionist reads at 8am.

Every AI response carries an `ai` object saying which driver answered and, if it degraded, why. The admin panel renders model-written and template-written text differently. Presenting the two identically is how these features lose people's trust the first time one is wrong.

Credentials are per workspace and treated like a payment method: verified before they are stored, encrypted at rest, never returned by any endpoint, never logged, and owner-only — staff use every AI feature and see nothing about the key that pays for it.

The provider is a config entry, not a code path. Two drivers cover the field — the Anthropic SDK, and one HTTP driver against the OpenAI Chat Completions shape that OpenAI, DeepSeek, Groq, Together, Mistral, OpenRouter and Ollama all speak. And where nobody has told the application what a model costs, it says so: an unpriced model reports spend as **untracked**, never as zero, because the two look identical in a sum and mean opposite things.

→ [`docs/AI.md`](docs/AI.md)

### 4. The fallback is a real implementation, not an apology

Setting `AI_DRIVER=heuristic` — or simply not configuring a key — routes every task to a deterministic implementation good enough that the demo is genuinely usable.

The booking parser handles relative dates, weekday names, parts of the day and explicit clock times, and matches services by owner-configured keywords. With no key at all:

```
"I need a haircut next tuesday afternoon"  → Cut & finish, 25 Aug–1 Sep, afternoon
"my scalp is really itchy, anything soon?" → Scalp treatment, next 7 days
"I think my hair is thinning"              → Online hair consultation
"I want a manicure"                        → "Which service did you have in mind?"
```

That last one matters most: below its confidence floor it asks rather than guesses. Booking someone into the wrong chair costs them an afternoon; asking costs them one tap.

This is why the test suite needs no secret, why CI makes no network calls, and why an Anthropic outage degrades the product instead of breaking it. English is genuinely ambiguous — "next Tuesday" means different weeks to different people — so the parser searches *both* readings rather than picking one and being wrong half the time.

---

## What is in here

**Customer-facing**

- Landing page with services and team, deep-linkable per service
- Three-step booking flow: describe it in plain English → pick a real slot → confirm
- Works entirely without the assistant — browsing services is always available
- Confirmation page with a quotable reference (`BL-7Q4M2X`), self-service cancellation inside the window
- Times rendered in the customer's own timezone, and confirmed in it

**Business-facing**

- Dashboard: today's diary, AI briefing, revenue, no-show rate, and what it cost
- Diary with filters (status, staff, service, risk band, date range, free-text) and one-click status changes
- Services with an AI copy drafter; team management; a weekly hours editor that understands lunch breaks and overnight shifts
- Time off that reports conflicting bookings instead of silently cancelling them
- An AI usage page: calls, tokens, latency, cache hits, failures and spend against a monthly budget
- Bring-your-own provider, per workspace — Anthropic, OpenAI, DeepSeek, or any OpenAI-compatible endpoint. Verified before it is saved, encrypted at rest, removable without breaking anything

**Platform**

- 38 REST endpoints under `/api/v1`, one error envelope, generated OpenAPI 3.1
- Multi-tenant from the first migration — global scope on read, auto-fill on write, explicit escape hatch
- Sanctum tokens for API clients; the admin panel is a client of the same API over its session cookie
- Roles enforced per record by policies, not just per route

## Stack

| | |
|---|---|
| **Backend** | Laravel 13.26, PHP 8.4, MySQL 8 |
| **Frontend** | Vue 3.5 + Inertia 2, TypeScript, Tailwind CSS 4, Vite 8 |
| **Auth** | Laravel Sanctum (bearer tokens + SPA session) |
| **AI** | Anthropic PHP SDK · one HTTP driver for every OpenAI-compatible provider · structured outputs |
| **Quality** | Pest 4, PHPStan (larastan) level 5, Laravel Pint, GitHub Actions |
| **Docs** | Scramble (OpenAPI 3.1 from the code), Postman collection |

Roughly 9,500 lines of PHP across 115 files, 3,800 lines of TypeScript and Vue across 24 components, and 2,600 lines of tests.

## Tests

```bash
./vendor/bin/pest
```

```
Tests:    203 passed (696 assertions)
Duration: 5.22s
```

| Suite | What it covers |
|---|---|
| `tests/Unit` | Interval arithmetic and the booking state machine. Pure, no database |
| `tests/Feature/Domain` | Availability engine (timezones, DST, buffers, grid alignment), risk scorer, the offline parser |
| `tests/Feature/Api` | Every endpoint group, the error envelope, authorisation from three angles, AI degradation |
| `tests/Feature/TenantIsolationTest` | The failure that would not crash anything: one business reading another's data |
| `tests/Concurrency` | Row locks and four forked processes racing for one slot |

The suite runs against **MySQL, not SQLite in memory** — the double-booking guard depends on `SELECT … FOR UPDATE`, and a suite that runs on a database without the feature under test passes for the wrong reason.

`AI_DRIVER=heuristic` throughout, so there is no network call, no secret and no bill.

```bash
./vendor/bin/phpstan analyse   # level 5, clean
./vendor/bin/pint --test       # clean
npm run build                  # vue-tsc runs first; a type error fails the build
```

## Performance

Every number below came out of `php artisan demo:bench`, against the seeded 570-booking workspace. Run it yourself; the absolutes depend on your machine, the ratios do not.

| | Median | Queries |
|---|---:|---:|
| Availability — 7 days, all staff, cold | 16.4 ms | 13 |
| Availability — 7 days, warm cache | 0.8 ms | 2 |
| Availability — 30 days, all staff, cold | 44.0 ms | 13 |
| Admin diary — 25 rows, eager loaded | 4.2 ms | 5 |
| Admin diary — the same 25 rows, lazily loaded | 17.1 ms | **101** |
| Dashboard statistics for today | 1.5 ms | 6 |

Two things worth pulling out.

**The query count does not grow with the range.** Seven days and thirty days both cost 13 queries; only the in-memory interval arithmetic grows. That is what computing from rules buys you — the alternative reads a row per slot.

**5 queries against 101 is the same page either way.** The last two rows render identical output; the difference is one `with()`. On six seeded services it is invisible, and at fifty thousand bookings it is the whole problem. `Model::shouldBeStrict()` is on in local, so a lazy load is an exception during development rather than a slow page six months later — it caught two real N+1s while this was being built.

## Documentation

| | |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Layers, request lifecycle, multi-tenancy, where a change goes |
| [docs/AVAILABILITY.md](docs/AVAILABILITY.md) | The algorithm, worked examples, timezone and DST rules |
| [docs/AI.md](docs/AI.md) | Driver design, prompts, structured outputs, cost, safety, observability |
| [docs/API.md](docs/API.md) | Endpoint reference, conventions, error codes, worked examples |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema, ERD, index rationale |
| [docs/DECISIONS.md](docs/DECISIONS.md) | Decision records — what was chosen, what was rejected, and what would change it |
| [docs/TESTING.md](docs/TESTING.md) | How the suite is structured and why the concurrency test forks |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Running it somewhere real |
| [docs/USER-MANUAL-BN.md](docs/USER-MANUAL-BN.md) | বাংলা ব্যবহারকারী নির্দেশিকা — the whole app in Bengali, use case by use case |

## What this is not

Being straight about the edges is part of the work.

- **Not a product.** No payments, no email delivery, no SMS, no calendar sync, no recurring appointments. The scaffolding is there; the integrations are not.
- **The risk weights are illustrative defaults**, chosen to be explainable rather than fitted to data. On a live deployment they are the first thing you would replace, once the business has enough history to fit them properly. The admin panel says so too.
- **Multi-tenancy is a shared schema with a scoped column.** That is the right call at this size and the wrong one past a certain scale; [docs/DECISIONS.md](docs/DECISIONS.md) says where the line is.
- **Every business, person, booking and review in the seed data is invented.**

## Licence

MIT — see [LICENSE](LICENSE). Yours to take apart.
