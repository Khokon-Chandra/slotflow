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
