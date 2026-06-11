# Database

MySQL 8, thirteen migrations, eleven business tables. Every index in here exists for a query that is written down.

---

## ERD

```mermaid
erDiagram
    TENANTS ||--o{ USERS : "has"
    TENANTS ||--o{ SERVICES : "offers"
    TENANTS ||--o{ STAFF : "employs"
    TENANTS ||--o{ CUSTOMERS : "serves"
    TENANTS ||--o{ BOOKINGS : "owns"

    USERS ||--o| STAFF : "may be"
    USERS ||--o| CUSTOMERS : "may be"

    SERVICES }o--o{ STAFF : "performed by"

    STAFF ||--o{ AVAILABILITY_RULES : "works"
    STAFF ||--o{ TIME_OFF : "is away"
    STAFF ||--o{ BOOKINGS : "attends"

    SERVICES ||--o{ BOOKINGS : "booked as"
    CUSTOMERS ||--o{ BOOKINGS : "makes"

    BOOKINGS ||--o| BOOKING_RISK_ASSESSMENTS : "scored by"

    TENANTS {
        id bigint PK
        slug string UK
        timezone string "IANA"
        currency char3
        settings json "per-tenant booking rules"
    }
    USERS {
        id bigint PK
        tenant_id bigint FK
        email string "unique per tenant"
        role enum "owner|staff|customer"
        timezone string
    }
    SERVICES {
        id bigint PK
        tenant_id bigint FK
        slug string "unique per tenant"
        keywords string "what customers actually type"
        duration_minutes smallint
        buffer_minutes smallint "turnaround"
        price_cents int
    }
    STAFF {
        id bigint PK
        tenant_id bigint FK
        user_id bigint FK "nullable — a diary needs no login"
        timezone string "may differ from the tenant"
    }
    AVAILABILITY_RULES {
        id bigint PK
        staff_id bigint FK
        weekday tinyint "0=Sunday"
        starts_at time "wall clock, staff zone"
        ends_at time
        effective_from date "nullable"
    }
    TIME_OFF {
        id bigint PK
        staff_id bigint FK
        starts_at datetime "UTC"
        ends_at datetime "UTC"
    }
    CUSTOMERS {
        id bigint PK
        tenant_id bigint FK
        email string "unique per tenant"
        completed_count int "denormalised"
        no_show_count int "denormalised"
        cancelled_count int
    }
    BOOKINGS {
        id bigint PK
        tenant_id bigint FK
        reference string "BL-7Q4M2X"
        starts_at datetime "UTC"
        ends_at datetime "UTC — told to the customer"
        blocks_until datetime "UTC — reserved in the diary"
        status enum
        customer_timezone string
        price_cents int "snapshot"
    }
    BOOKING_RISK_ASSESSMENTS {
        id bigint PK
        booking_id bigint FK,UK
        score tinyint "0-100, deterministic"
        band string "low|medium|high"
        factors json "the arithmetic"
        rationale text "model-written"
        generated_by string "claude|heuristic"
    }
    AI_INTERACTIONS {
        id bigint PK
        tenant_id bigint FK
        task string
        driver string
        cost_micros bigint "integer, so a month sums cleanly"
        latency_ms int
        succeeded bool
    }
```

---

## Decisions in the schema

### Money is integer minor units

`price_cents`, `deposit_cents`, never a float. `0.1 + 0.2 != 0.3` is not a rounding inconvenience when it is somebody's invoice. Formatting happens in the browser, which is the only place that knows the locale.

The same reasoning gives `ai_interactions.cost_micros` — cost in millionths of a dollar, as an integer — so summing a month of rows cannot drift.

### Times are UTC, except where they must not be

| Column | Type | Why |
|---|---|---|
| `bookings.starts_at` | `datetime` UTC | An absolute instant |
| `time_off.starts_at` | `datetime` UTC | An absolute interval |
| `availability_rules.starts_at` | `time` | A **weekly pattern**, not a point on the timeline |

"Tuesday 09:00" is a different instant in January and in July. Storing it as a datetime is how a booking system ends up an hour out twice a year. It is a wall-clock time, interpreted in `staff.timezone` on the day it applies.

### `bookings` has two end times

| Column | Means | Read by |
|---|---|---|
| `ends_at` | `starts_at + duration` | The customer's confirmation |
| `blocks_until` | `ends_at + buffer`, snapshotted | The diary and the overlap check |

Two columns because they answer two different questions. Keeping the buffer out of `ends_at` keeps the confirmation honest; keeping it *in* a stored column makes the double-booking check one indexed SQL predicate rather than a PHP-side reconciliation the database cannot enforce.

Snapshotted, so changing a service's turnaround does not retroactively shift appointments already booked.

### `customers` carries denormalised counters

`completed_count`, `no_show_count` and `cancelled_count` duplicate what a `COUNT` over `bookings` would tell you. That is deliberate: the risk scorer reads them on every booking write, and a `COUNT` per booking is the classic N+1 that shows up six months in.

They are maintained inside the same transaction that changes a booking's status, so they cannot drift from the rows they summarise.

### `reference` is not the primary key

Sequential integers in a URL tell the world how many bookings you take and invite enumeration. `BL-7Q4M2X` is safe to read out over the phone and safe to put in a link. The alphabet omits I, O, 0 and 1 — the characters people mishear.

Unique per tenant, with a collision retry, because "unlikely" is not "impossible" when the column is unique.

### Email is unique per tenant, not globally

The same person can be a customer of two businesses on the platform. A global unique constraint would make the second one impossible, and there is a test for it.

### Customers are separate from users

Most people who book never create an account, and the business still needs their history. A `customer` may point at a `user`, and registration adopts any matching guest record — so signing up later keeps a customer's history, and therefore their risk profile.

---

## Indexes

Every one of these covers a query that exists.

### `bookings`

```sql
UNIQUE (tenant_id, reference)
INDEX  (tenant_id, staff_id, starts_at)   -- bookings_diary_index
INDEX  (tenant_id, status, starts_at)     -- bookings_status_index
INDEX  (tenant_id, customer_id)
```

**`bookings_diary_index`** is the hot one. It serves the overlap check inside the booking guard and the blocker lookup in the availability engine:

```sql
SELECT … WHERE tenant_id = ? AND staff_id = ?
           AND starts_at < ? AND blocks_until > ?
```

Column order matters: `tenant_id` and `staff_id` are equality predicates and come first; `starts_at` is a range and comes last. Putting the range column earlier would stop the index being useful for the equalities after it.

**`bookings_status_index`** serves the admin diary — filter by status, order by time — and the metrics aggregation, which groups by status over a rolling window.

### `availability_rules`

```sql
INDEX (tenant_id, staff_id, weekday)
```

The availability engine's inner loop: every slot lookup filters by staff and weekday.

### `time_off`

```sql
INDEX (tenant_id, staff_id, starts_at, ends_at)   -- time_off_lookup_index
```

Range overlap: "any time off intersecting this window".

### `ai_interactions`

```sql
INDEX (tenant_id, task, created_at)
INDEX (created_at)
```

The first serves the admin usage page; the second the monthly spend guard, which sums across all tenants.

---
