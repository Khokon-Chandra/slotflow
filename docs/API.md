# API reference

Version 1. Interactive reference, generated from the code: **`/docs/api`**. Postman collection: [`postman/`](../postman/).

---

## Conventions

### Workspace

Every request needs one.

| How | Who uses it |
|---|---|
| `X-Tenant: bright-lane` header | API clients |
| `?tenant=bright-lane` | The public booking page |
| Implicit | Anything authenticated — a token carries its own workspace |

A token's workspace wins. If an authenticated request also names a *different* one, the answer is `403` — that is not a routing quirk, it is someone probing.

### Time

**UTC on the wire.** Send ISO-8601 instants with an offset. These are the same booking and are stored identically:

```
2026-09-01T09:00:00+02:00
2026-09-01T07:00:00Z
```

A bare `2026-09-01 09:00` is ambiguous and rejected.

**A required `tz` on anything that renders time for a human.** IANA identifiers only — `Europe/Vienna`, `Asia/Dhaka`. Offsets (`+02:00`) and abbreviations (`CEST`) are rejected: an offset cannot express daylight saving, and an abbreviation is ambiguous. Both look like they work until the clocks change.

Responses carry both forms:

```json
{ "starts_at": "2026-09-01T07:00:00+00:00", "local_starts_at": "2026-09-01T09:00:00+02:00" }
```

### Money

Integer minor units plus a currency code. Never a float, never pre-formatted — formatting is the client's job, because only the client knows the locale.

```json
{ "price_cents": 4800, "currency": "EUR" }
```

### Errors

One shape, whatever went wrong:

```json
{
  "error": {
    "code": "slot_unavailable",
    "message": "That slot has just been taken. Please pick another time.",
    "context": { "requested_start": "2026-09-01T07:00:00+00:00", "staff_id": 1 }
  }
}
```

Branch on `error.code` — it is the stable part of the contract, and changing one is a breaking change. Validation failures add a `fields` object keyed by input name.

| Code | Status | Means |
|---|---|---|
| `validation_failed` | 422 | See `error.fields` |
| `unauthenticated` | 401 | No valid bearer token |
| `forbidden` | 403 | Authenticated, not permitted |
| `not_found` | 404 | Does not exist, or you cannot see it |
| `bad_request` | 400 | No workspace specified |
| `slot_unavailable` | **409** | The slot went. Normal on a busy diary — refresh and re-offer |
| `insufficient_notice` | 422 | Inside the minimum-notice window |
| `beyond_booking_horizon` | 422 | Past the advance-booking limit |
| `service_inactive` | 422 | Not bookable online |
| `staff_service_mismatch` | 422 | That person does not offer that service |
| `invalid_booking_transition` | 422 | Illegal status change. `context.allowed` lists what is |
| `service_has_bookings` | 409 | Cannot delete; deactivate instead |
| `ai_key_rejected` | 422 | Anthropic refused the key. `message` says why; nothing was stored |
| `no_key_installed` | 422 | Asked to re-check a key this workspace does not have |
| `staff_has_bookings` | 409 | Cannot delete; reassign first |

404s never name the model class.

### Authentication

Bearer tokens (Laravel Sanctum):

```
Authorization: Bearer 3|xxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Get one from `POST /auth/login` or `POST /auth/register`. `POST /auth/logout` revokes only the token used for that request.

---

## Endpoints

37 in total, all under `/api/v1`.

### Auth

| | | Auth |
|---|---|---|
| `POST` | `/auth/register` | — |
| `POST` | `/auth/login` | — |
| `GET` | `/auth/me` | token |
| `POST` | `/auth/logout` | token |

Registration adopts any existing guest bookings for that email, so a customer who signs up later keeps their history — and therefore their risk profile.

Login is throttled per email **and** per IP: per-IP alone lets a botnet spray one account, per-email alone lets one host enumerate the customer list. A wrong password and an unknown address return the same message; distinguishing them tells an attacker which addresses are worth guessing.

### Catalogue

| | | Auth |
|---|---|---|
| `GET` | `/services` | — |
| `GET` | `/services/{slug}` | — |
| `POST` | `/services` | owner |
| `PUT` | `/services/{slug}` | owner |
| `DELETE` | `/services/{slug}` | owner |
| `GET` | `/staff` | — |
| `GET` | `/staff/{id}` | — |
| `POST` | `/staff` | owner |
| `PUT` | `/staff/{id}` | owner |
| `DELETE` | `/staff/{id}` | owner |

Deleting either refuses with `409` while future bookings exist. Deactivate instead — deleting would orphan appointments people have already been promised.

### Working hours

| | | Auth |
|---|---|---|
| `GET` | `/staff/{id}/availability-rules` | staff/owner |
| `PUT` | `/staff/{id}/availability-rules` | self or owner |
| `GET` | `/staff/{id}/time-off` | staff/owner |
| `POST` | `/staff/{id}/time-off` | self or owner |
| `DELETE` | `/staff/{id}/time-off/{timeOff}` | self or owner |

`PUT` replaces the whole week. A replace rather than a merge, so sending the same payload twice leaves the same schedule.

```json
{
  "rules": [
    { "weekday": 2, "starts_at": "09:00", "ends_at": "13:00" },
    { "weekday": 2, "starts_at": "14:00", "ends_at": "18:00" }
  ]
}
```

`weekday` is 0 = Sunday … 6 = Saturday. Times are wall-clock in the staff member's own timezone. Two entries on one day is a lunch break; an end earlier than a start is an overnight shift, and is handled.

Booking time off does **not** cancel the appointments inside it. They come back in `meta.conflicting_bookings`:

```json
{ "data": { … }, "meta": { "conflicting_bookings": ["BL-7Q4M2X", "BL-K3PN91"] } }
```

Silently dropping appointments someone has already been promised is not a decision an API should make.

### Availability

| | | Auth |
|---|---|---|
| `GET` | `/availability` | — |

```
GET /api/v1/availability
      ?service_id=1
      &from=2026-09-01
      &until=2026-09-07
      &tz=Europe/Vienna
      &staff_id=2          # optional
```

`tz` is required. `date` is shorthand for `from` = `until`. Maximum span 31 days, enforced in validation.

```json
{
  "data": {
    "service": { "id": 1, "name": "Cut & finish", "duration_minutes": 45 },
    "timezone": "Europe/Vienna",
    "from": "2026-09-01", "until": "2026-09-07",
    "slot_count": 186,
    "days": [{
      "date": "2026-09-01",
      "slots": [{
        "starts_at": "2026-09-01T07:00:00+00:00",
        "ends_at": "2026-09-01T07:45:00+00:00",
        "local_starts_at": "2026-09-01T09:00:00+02:00",
        "local_date": "2026-09-01",
        "local_time": "09:00",
        "staff_id": 1, "staff_name": "Maya Brenner",
        "timezone": "Europe/Vienna"
      }]
    }]
  },
  "meta": { "cache_ttl_seconds": 60, "min_notice_minutes": 120, "max_advance_days": 60 }
}
```

`meta` tells you the server already cached this, and for how long — a client that caches too should know.

### Bookings

| | | Auth |
|---|---|---|
| `POST` | `/bookings` | — |
| `GET` | `/bookings/{reference}` | — (with `?email=`) |
| `GET` | `/bookings` | token |
| `PATCH` | `/bookings/{reference}/cancel` | token |
| `GET` | `/bookings/{reference}/risk` | staff/owner |

Creating is public. Making a customer register before they can make an appointment is the easiest way to lose the appointment.

```json
{
  "service_id": 1,
  "staff_id": 1,
  "starts_at": "2026-09-01T07:00:00Z",
  "customer_name": "Ada Lovelace",
  "customer_email": "ada@example.test",
  "customer_phone": "+43 660 1234567",
  "customer_timezone": "Europe/Vienna",
  "notes": "First visit."
}
```

`201` on success, with a quotable `reference` like `BL-7Q4M2X`. The alphabet omits I, O, 0 and 1 — the characters people mishear reading a code aloud.

**`409 slot_unavailable`** means the slot went between the customer seeing it and confirming it. On a busy diary that is a normal Tuesday, not a failure: refresh availability and offer another time. It is enforced by a transaction with a row lock, and tested by forking four processes at the same slot — see [TESTING.md](TESTING.md).

A guest may read their own booking by passing the email it was made with. Enough for a confirmation link, not enough to enumerate. Customer details and risk are visible only to the business.

### Admin

| | | Auth |
|---|---|---|
| `GET` | `/admin/bookings` | staff/owner |
| `PATCH` | `/admin/bookings/{reference}/status` | staff/owner |
| `GET` | `/admin/metrics` | staff/owner |
| `GET` | `/admin/ai-usage` | staff/owner |

The diary is paginated and filterable by `status`, `staff_id`, `service_id`, `risk`, `from`, `to` and a free-text `search` over reference, customer name and email. A staff token returns only that person's diary.

Status changes are validated against the state machine:

```
pending    → confirmed · completed · cancelled · no_show
confirmed  → completed · cancelled · no_show
completed  → (terminal)
cancelled  → (terminal)
no_show    → (terminal)
```

An illegal move returns `422 invalid_booking_transition` with `context.allowed`. Marking a no-show updates the customer's counters, which feeds the risk model.

### AI credentials

| | | Auth |
|---|---|---|
| `GET` | `/admin/ai-settings` | **owner** |
| `PUT` | `/admin/ai-settings` | **owner** |
| `PUT` | `/admin/ai-settings/key` | **owner** |
| `DELETE` | `/admin/ai-settings/key` | **owner** |
| `POST` | `/admin/ai-settings/verify` | **owner** |

Owner only, and throttled to 12/min — the store and verify endpoints each call Anthropic, so without a limit they are a free way to probe a key for validity.

A workspace can bring its own Anthropic key. It takes precedence over the platform key in `.env`; remove it and the workspace falls back to the platform key, then to the built-in implementations. Nothing breaks at any step.

```json
GET /api/v1/admin/ai-settings
{
  "data": {
    "settings": {
      "has_key": true,
      "masked_key": "sk-ant-…Ab12",
      "key_set_at": "2026-08-20T09:14:00+00:00",
      "last_checked_at": "2026-08-20T09:14:00+00:00",
      "last_check_passed": true,
      "last_check_error": null,
      "model": "claude-opus-5",
      "monthly_budget_usd": 40
    },
    "effective": {
      "driver": "claude",
      "key_source": "tenant",
      "model": "claude-opus-5",
      "monthly_budget_usd": 40,
      "configured_driver": "auto"
    },
    "available_models": [ … ]
  }
}
```

`settings` is what this workspace stored. `effective` is what is actually in force after falling back to the platform — they diverge, and the difference is the whole point of the object.

**`PUT /admin/ai-settings/key` verifies before it stores.** The key is checked against Anthropic first; if the check fails, the response is `422 ai_key_rejected` with a message saying why, and **nothing is written**. A workspace that looks configured while every call quietly falls back is worse than one that is plainly unconfigured.

```json
PUT /api/v1/admin/ai-settings/key
{ "api_key": "sk-ant-api03-…", "model": "claude-opus-5" }
```

Three things to know:

- **No endpoint returns the key.** Not this one, not `show`, not after an update. Only the last four characters.
- **`model` is restricted** to models this application has prices for. Anything else would report a spend of zero.
- **`monthly_budget_usd` and `model` accept null**, meaning "use the platform default".

`POST /admin/ai-settings/verify` re-checks the stored key and records the outcome. A key that verified when it was saved can be revoked later; `last_check_passed` says when it was last known good, not that it works now.

### AI features

| | | Auth |
|---|---|---|
| `POST` | `/ai/booking-assistant` | — (throttled 20/min) |
| `GET` | `/ai/daily-briefing` | staff/owner |
| `POST` | `/ai/service-description` | owner |

```json
POST /api/v1/ai/booking-assistant
{ "text": "I need a haircut next Tuesday afternoon", "tz": "Europe/Vienna", "limit": 6 }
```

```json
{
  "data": {
    "intent": { "service_id": 1, "confidence": "high", "date_from": "2026-08-25",
                "date_until": "2026-09-01", "time_of_day": "afternoon", … },
    "service": { "id": 1, "name": "Cut & finish", … },
    "slots": [ … ],
    "relaxed": false,
    "message": "Looking for Cut & finish between 25 Aug and 1 Sep in the afternoon.",
    "ai": { "driver": "claude", "model": "claude-opus-5", "cached": false, "degraded_reason": null }
  }
}
```

Three things to know:

- **This endpoint never writes.** The model narrows a search; the slots come from the availability engine; booking still means `POST /bookings`.
- **`relaxed: true`** means nothing matched the stated preference, so the window was widened once. Say so in your UI.
- **`ai.driver`** is `claude` or `heuristic`. Everything works without an API key, and `degraded_reason` says why when it fell back. Show it — a user reading a sentence about their business should know whether a model wrote it.

`text` is capped at 400 characters. It is a booking request, not a conversation.

---

## Worked example

```bash
BASE=http://localhost:8000/api/v1
TENANT='X-Tenant: bright-lane'

# 1 — what can be booked
curl -s "$BASE/services" -H "$TENANT" | jq '.data[] | {id, name, duration_minutes, price_cents}'

# 2 — when
curl -s "$BASE/availability?service_id=1&from=2026-09-01&until=2026-09-07&tz=Europe/Vienna" \
  -H "$TENANT" | jq '.data.days[0].slots[0]'

# 3 — book it
curl -s -X POST "$BASE/bookings" -H "$TENANT" -H 'Content-Type: application/json' -d '{
  "service_id": 1, "staff_id": 1,
  "starts_at": "2026-09-01T07:00:00Z",
  "customer_name": "Ada Lovelace",
  "customer_email": "ada@example.test",
  "customer_timezone": "Europe/Vienna"
}' | jq '.data.reference'

# 4 — send exactly the same request again, and watch the guard work
#     → 409 { "error": { "code": "slot_unavailable", … } }
```

---

## Versioning

`/api/v1` from the first commit. It costs nothing today and is the only thing that lets a response shape change in eighteen months without breaking every client at once.

Within v1: new fields may be added, and new optional parameters accepted. Removing a field, renaming one, or changing an `error.code` is a v2.
