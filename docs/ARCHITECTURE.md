# Architecture

How the pieces fit, and where a change goes.

---

## The shape of it

```
        ┌──────────────────────────────┐   ┌───────────────────────────┐
        │  Public booking page         │   │  Admin panel              │
        │  Vue 3 · Inertia             │   │  Vue 3 · Inertia          │
        └──────────────┬───────────────┘   └─────────────┬─────────────┘
                       │                                 │
     initial props ────┤                                 ├──── initial props
     (server-rendered) │                                 │     (server-rendered)
                       │                                 │
     fetch ────────────┴──────────────┬──────────────────┴──── fetch (session cookie)
                                      │
                             ┌────────▼─────────┐
                             │   /api/v1        │  32 endpoints, one error shape
                             │   Controllers    │  Form requests · Resources · Policies
                             └────────┬─────────┘
                                      │
                    ┌─────────────────┼──────────────────┐
                    │                 │                  │
          ┌─────────▼───────┐ ┌───────▼────────┐ ┌───────▼────────┐
          │ Availability    │ │ Booking        │ │ Risk           │
          │ Engine          │ │ Service        │ │ Scorer         │
          │ (read)          │ │ (write, locks) │ │ (deterministic)│
          └─────────┬───────┘ └───────┬────────┘ └───────┬────────┘
                    │                 │                  │
                    └─────────────────┼──────────────────┘
                                      │            ┌─────────────────┐
                             ┌────────▼─────────┐  │  AI layer       │
                             │  Eloquent        │  │  AiManager      │
                             │  + tenant scope  │  │  ├─ Claude      │
                             └────────┬─────────┘  │  └─ Heuristic   │
                                      │            └────────┬────────┘
                             ┌────────▼─────────┐           │
                             │  MySQL 8         │◄──────────┘
                             └──────────────────┘        (advisory only —
                                                          never writes)
```

Two rules explain most of the layout.

**Business rules live in `app/Domain`, not in controllers.** A controller validates, authorises, calls one domain service and renders a resource. That is why the admin panel, the public API and the AI assistant cannot disagree about whether a slot is free: there is exactly one place where that is decided, and therefore exactly one place where it can be wrong.

**The AI layer depends on the domain; the domain never depends on the AI layer.** `NoShowRiskScorer` needs a sentence written, so it depends on `App\Domain\Risk\Contracts\RiskNarrator` — an interface declared in the domain and implemented in `App\Ai`. Swapping a language model for a template is a container binding, not a refactor.

---

## Directory map

| Path | What lives here |
|---|---|
| `app/Domain/Availability` | The slot engine, `TimeRange` interval arithmetic, query DTO |
| `app/Domain/Booking` | `BookingService` (the transactional guard), input DTO, reference generator |
| `app/Domain/Risk` | Deterministic no-show scoring, the narrator contract |
| `app/Domain/Reporting` | Day statistics — the numbers the dashboard and briefing are built from |
| `app/Ai` | Driver abstraction, Claude client, heuristic implementations, the four tasks |
| `app/Http/Controllers/Api/V1` | Versioned JSON API |
| `app/Http/Controllers/Web` | Inertia page controllers |
| `app/Http/Requests/Api` | Validation, tenant-scoped `exists` rules |
| `app/Http/Resources` | The API's response shapes |
| `app/Policies` | Who may act. **Not** what move is legal — see below |
| `app/Models` | Eloquent models, all business tables behind `BelongsToTenant` |
| `app/Support` | `TenantContext` |
| `resources/js` | Vue pages, layouts, components, TypeScript helpers |

---

## Request lifecycle

### An API request

```
  POST /api/v1/bookings
       │
   1.  ├─ tenant middleware ......... resolves the workspace, binds TenantContext
   2.  ├─ auth:sanctum (if required) . bearer token or SPA session
   3.  ├─ StoreBookingRequest ....... validation; `exists` rules scoped to the tenant
   4.  ├─ BookingController ......... builds a BookingData DTO
   5.  ├─ BookingService::create() ... rules → transaction → lock → re-check → insert
   6.  ├─ AvailabilityEngine ........ cache invalidated for the tenant
   7.  ├─ NoShowRiskScorer .......... scored outside the transaction
   8.  └─ BookingResource ........... 201, or 409 slot_unavailable
```

Step 7 is outside the transaction on purpose: scoring reads the customer's history and, when a key is configured, calls an external API. Neither belongs inside a lock on a staff member's diary.

### An Inertia page

Page controllers assemble the initial props server-side; everything interactive afterwards goes through `/api/v1` from the browser, authenticated by the session cookie (`$middleware->statefulApi()`).

That is deliberate. The admin panel is a client of the same public API everyone else uses, which is the cheapest way to keep that API honest — if a response shape is awkward for us, it is awkward for them, and we find out immediately rather than in a support ticket.

Shared props are **closures**, not values:

```php
'tenant' => function (): ?array {
    $tenant = app(TenantContext::class)->get();
    // …
},
```

Inertia's middleware runs `share()` on the way *in*, before route middleware — so a value read eagerly is read before the tenant has been resolved, and every page renders with an empty header. A closure is evaluated at render time. The bug it avoids is quiet: nothing errors, the page just looks wrong.

---

## Multi-tenancy

One schema, a `tenant_id` on every business table, isolation enforced in three layers.

**1. Read — a global scope.** [`TenantScope`](../app/Models/Scopes/TenantScope.php) adds `where tenant_id = ?` to every query on a model using `BelongsToTenant`. When no tenant is bound (console commands, the seeder, tenant resolution itself) the scope is inert: it never returns another business's rows, it simply does not filter.

**2. Write — automatic fill.** The same trait fills `tenant_id` on create, so no caller has to remember.

**3. Validation — scoped existence.** `exists:services,id` runs a raw query and ignores Eloquent scopes. Left alone, an id belonging to another business passes validation and only fails at the lookup — and "valid, then 404" is distinguishable from "invalid", which is enough to enumerate. [`ScopesExistenceToTenant`](../app/Http/Requests/Api/Concerns/ScopesExistenceToTenant.php) closes that.

Forgetting a `where` clause is the single most common way a multi-tenant application leaks data. Making the filter the default, and the escape hatch explicit and greppable — `withoutTenantScope()` — inverts the risk: every dangerous call site is a place to look twice, and there are four of them.

[`TenantIsolationTest`](../tests/Feature/TenantIsolationTest.php) runs against two fully-seeded workspaces, because a leak test against an empty second tenant tests nothing.

### Resolution order

| Source | Used by | Notes |
|---|---|---|
| The authenticated user's own tenant | Everything authenticated | Cannot be spoofed |
| `X-Tenant` header | API clients | |
| `?tenant=` query parameter | The public booking page | |

If a request is authenticated *and* names a different workspace, that is not a routing quirk — it gets a 403. An attempt worth logging is worth refusing.

---
