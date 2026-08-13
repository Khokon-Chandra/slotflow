# Availability

The hard part of any booking system, and the part most schemas get wrong.

---

## The model

There is no `slots` table.

The naive design is a row per bookable fifteen minutes, generated ahead of time. It reads beautifully and breaks the first time anything changes:

| What changes | What breaks |
|---|---|
| A staff member changes their hours | Every future slot row for them is wrong until a backfill runs |
| A service gets longer | Slots that were bookable now overlap the next appointment |
| The clocks go forward | An hour of slots exists that never happened |
| A new staff member joins | Nothing is bookable with them until generation runs |

Every one of those is fixed by a backfill, and the day someone forgets one, the system quietly sells time that does not exist. Selling time twice is the failure this whole application exists to prevent.

So availability is stored as **rules** and computed on demand:

```
free = working hours − time off − existing bookings (with their buffers)
```

| Table | Holds | Timezone |
|---|---|---|
| `availability_rules` | "Maya works Tuesdays 09:00–13:00" | Wall-clock, in the **staff member's** zone |
| `time_off` | "Away 14–18 September" | Absolute UTC instants |
| `bookings` | `starts_at`, `ends_at`, `blocks_until` | Absolute UTC instants |

Weekly rules are wall-clock because a weekly pattern is not a point on the timeline. "Tuesday 09:00" is a different instant in January and in July, and storing it as a datetime is how a booking system ends up an hour out twice a year.

---

## The algorithm

[`AvailabilityEngine::compute()`](../app/Domain/Availability/AvailabilityEngine.php), step by step.

### 1. Clamp the window

The requested range is narrowed to what is actually bookable: no earlier than `now + min_notice_minutes`, no later than `max_advance_days`. A request may also not span more than 31 days — rejected in validation with a 422, so the caller gets something they can act on rather than a slow query or a 500.

### 2. Expand the weekly rules

For each staff member who performs the service, iterate calendar dates **in that person's timezone** — because that is the clock the rules are written in — and turn each matching rule into a UTC interval:

```php
$start = CarbonImmutable::parse("{$date} {$rule->starts_at}", $staff->timezone);
$end   = CarbonImmutable::parse("{$date} {$rule->ends_at}", $staff->timezone);

if ($end->lessThanOrEqualTo($start)) {
    $end = $end->addDay();          // an overnight shift: 22:00–02:00
}
```

Parsing wall-clock time *in the zone* lets the timezone database decide what the instant is. That single choice is what makes daylight saving work.

Iteration starts a day early, so an overnight shift beginning the day before the window can still contribute time inside it.

### 3. Subtract the blockers

Time off and existing bookings become intervals, and [`TimeRange::subtractAll()`](../app/Domain/Availability/TimeRange.php) removes them. Subtracting one interval from another yields zero, one or two pieces — the two-piece case is a lunchtime appointment in the middle of a shift.

Bookings are subtracted using `blocks_until`, not `ends_at`. See [buffers](#buffers) below.

Only *blocking* statuses count. A cancelled or missed booking keeps its row — it is the input to risk scoring — but releases the time.

### 4. Walk the grid

Each free interval is walked on the tenant's slot granularity (15 minutes by default), anchored to **local midnight** rather than to the interval start. A gap that begins at an awkward 10:07 — because the previous appointment's buffer ended there — yields 10:15, 10:30, not 10:07, 10:22.

A candidate is offered only if its **whole blocking window** — duration plus buffer — fits inside the free interval.

---

## Half-open intervals

Every comparison in the system is `[start, end)`:

```php
$this->start->lessThan($other->end) && $this->end->greaterThan($other->start)
```

With closed intervals, 09:00–10:00 and 10:00–11:00 "overlap" at the shared instant, and you end up writing `>=` and `<=` inconsistently in three places until back-to-back appointments mysteriously stop being bookable. That is a quieter bug than double booking and loses just as much money.

The same predicate appears in the SQL overlap check, so the read path and the write path cannot disagree:

```sql
WHERE starts_at < :candidate_end AND blocks_until > :candidate_start
```

---

## Buffers

A service has a `duration_minutes` and a `buffer_minutes`. The buffer is turnaround: cleaning the chair, writing notes, changing the room. It blocks the diary, is not shown to the customer, and is not billed.

Bookings therefore store **two** end times:

| Column | Means | Used by |
|---|---|---|
| `ends_at` | `starts_at + duration` | The confirmation the customer reads |
| `blocks_until` | `ends_at + buffer` (snapshotted at booking time) | The diary and the overlap check |

Two columns rather than one because they answer two different questions. Keeping the buffer out of `ends_at` means the confirmation email is honest; keeping it *in* a stored column means the double-booking check is one indexed SQL predicate instead of a PHP-side reconciliation the database cannot enforce.

The buffer is a snapshot, so changing a service's turnaround does not retroactively shift appointments already in the diary — which is the correct behaviour, not a limitation.

### Worked example

A 60-minute service with a 15-minute buffer. Working hours 09:00–13:00. One appointment already at 10:00.

```
09:00                    10:00        11:00  11:15                    13:00
  ├───────── free ─────────┤ booked      │ buffer │────── free ─────────┤
  │◄──── 60 minutes ──────►│◄─ 60 min ──►│◄ 15m ─►│
```

The 09:00–10:00 gap is exactly sixty minutes. It fits the appointment — and not the fifteen minutes of clearing up that has to follow it. **So it is not offered.** The first bookable slot is 11:15.

That is the buffer doing its job: booking into it would leave the staff member no turnaround. The behaviour is asserted directly in [`AvailabilityEngineTest`](../tests/Feature/Domain/AvailabilityEngineTest.php), alongside the case where the gap *is* 75 minutes and 09:00 is offered.

---

## Timezones

Three timezones are in play at once, and conflating any two is a bug:

| Zone | Where it comes from | What it governs |
|---|---|---|
| **Tenant** | `tenants.timezone` | The admin panel's clock; the business day boundary |
| **Staff** | `staff.timezone` | How weekly rules are interpreted |
| **Caller** | The required `tz` parameter | How slots are rendered back |

The `tz` parameter is **required**, never sniffed from a header and never defaulted to the server's. A booking API that guesses the caller's timezone is a booking API that is occasionally an hour wrong and never says so.

Only IANA identifiers are accepted. `+02:00` and `CEST` are rejected by [`ValidTimezone`](../app/Rules/ValidTimezone.php): an offset cannot express daylight saving, and an abbreviation is ambiguous — `CST` is three different zones. Both look like they work right up until the clocks change.

Every slot carries both representations:

```json
{
  "starts_at":       "2026-06-11T07:00:00+00:00",
  "local_starts_at": "2026-06-11T09:00:00+02:00",
  "local_time":      "09:00",
  "timezone":        "Europe/Vienna"
}
```

The instant is what the server compares; the local rendering is what a human reads. Sending only one is how a booking UI ends up an hour out.

### The seeded case

Bright Lane Studio is in `Europe/Vienna`. Dr. Priya Nair, its trichologist, works `Asia/Kolkata`, 09:00–13:00. In June that is 05:30–09:30 Vienna; in January, 04:30–08:30.

She is in the seed data on purpose. A timezone bug that only appears across zones is a timezone bug you will ship.

---

## Daylight saving

Both transitions have tests, and both assert against an ordinary week rather than a hard-coded count — so the test is about DST, not about the slot grid.

**Spring forward.** 29 March 2026, Europe/Vienna: 02:00 becomes 03:00. A 01:00–05:00 shift is three real hours, not four, and the engine offers exactly four fewer slots than the Sunday before. Those minutes did not exist; offering them would mean selling an appointment at a time that never happened.

**Autumn back.** 25 October 2026: 03:00 becomes 02:00. The same shift is five hours, and the engine offers four more slots.

**And the boundary.** A 09:00 Vienna appointment is `08:00Z` in March and `07:00Z` in April. The local time is stable across the transition; the instant is not. Getting that backwards is the classic version of this bug.

None of this needs special-casing in the engine. It falls out of parsing wall-clock time in the correct zone and letting the timezone database answer.

---

## Caching

Computed availability is cached for 60 seconds, keyed by tenant, a version counter, and a fragment covering the service, date range, timezone and staff filter.

Invalidation bumps the version counter — one atomic increment, cannot miss a key, and stale entries expire on their own TTL. Deleting keys means enumerating them, and the day the enumeration misses one you are selling time that is already sold.

The counter is bumped on every booking, availability-rule change and time-off change.

Measured on the seeded workspace (`php artisan demo:bench`):

| | Median | Queries |
|---|---:|---:|
| 7 days, cold | 16.4 ms | 13 |
| 7 days, warm | 0.8 ms | 2 |
| 30 days, cold | 44.0 ms | 13 |

**The query count does not grow with the range.** Rules, time off and bookings are each fetched once for the whole window; only the interval arithmetic grows. A `slots` table would read a row per candidate.

---

## What the cache does *not* protect

Availability is a read. It can be stale by up to 60 seconds, and a customer can be shown a slot that has just gone.

That is fine, and it is why [`BookingService`](../app/Domain/Booking/BookingService.php) re-checks inside a transaction with a row lock before inserting. The cache makes the page fast; the lock makes the booking correct. Confusing the two — trusting the read path to prevent double booking — is the mistake this design is built around.

---

## Limits, honestly

- **One service per booking.** No packages, no "cut and colour together". The schema would take a join table; the engine would need to compose durations.
- **No resource constraints.** A room, a chair or a machine that two staff members share is not modelled. Availability is per person.
- **No recurring appointments.** Every booking is a single instant.
- **The 31-day cap is a real cap.** Expanding a year of rules for a large team in one request is a query nobody should be able to ask for over HTTP.

---

Next: [AI.md](AI.md) — how the assisted features are built and bounded.
