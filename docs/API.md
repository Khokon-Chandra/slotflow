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
| `ai_credential_rejected` | 422 | The provider refused the credential. `message` says why; nothing was stored |
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

38 in total, all under `/api/v1`.

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

### AI providers

| | | Auth |
|---|---|---|
| `GET` | `/admin/ai-providers` | **owner** |
| `PUT` | `/admin/ai-providers/{provider}` | **owner** |
| `POST` | `/admin/ai-providers/{provider}/activate` | **owner** |
| `POST` | `/admin/ai-providers/{provider}/verify` | **owner** |
| `DELETE` | `/admin/ai-providers/{provider}` | **owner** |
| `PUT` | `/admin/ai-settings` | **owner** |

Owner only, throttled to 12/min — connecting and re-checking each call out to
the provider, so without a limit these are a free way to probe a key for
validity.

`{provider}` is a catalogue id: `anthropic`, `openai`, `deepseek`, or `custom`
for any other endpoint that speaks OpenAI Chat Completions.

Every response returns the same object, so a client never merges a partial
update into its own idea of the state:

```json
{
  "data": {
    "connected": [{
      "provider": "anthropic",
      "display_name": "Anthropic",
      "masked_key": "…Ab12",
      "model": "claude-opus-5",
      "is_active": true,
      "last_check_passed": true,
      "tracks_spend": true
    }],
    "effective": {
      "driver": "anthropic", "source": "workspace",
      "provider": "anthropic", "model": "claude-opus-5",
      "tracks_spend": true, "monthly_budget_usd": 25,
      "configured_driver": "auto"
    },
    "settings": { "monthly_budget_usd": null },
    "catalogue": [ … ]
  }
}
```

`connected` is what this workspace has set up. `effective` is what is actually
in force after falling back to the platform credential — they diverge, and the
difference is the whole point of the object.

```json
PUT /api/v1/admin/ai-providers/deepseek
{ "api_key": "sk-…", "model": "deepseek-chat" }

PUT /api/v1/admin/ai-providers/custom
{
  "api_key": "…", "model": "llama3.1:8b",
  "label": "Ollama on the office box",
  "base_url": "http://localhost:11434/v1",
  "input_rate_per_mtok": 0, "output_rate_per_mtok": 0
}
```

Six things to know:

- **Verified before stored.** The credential is checked against the provider
  first; a failure is `422 ai_credential_rejected` and **nothing is written**.
- **No endpoint returns a key.** Only the last four characters.
- **Exactly one provider is in force.** The first connected becomes active;
  `activate` switches and demotes the rest in the same transaction.
- **Disconnecting promotes the next**, rather than leaving a workspace with
  credentials and none in use.
- **`base_url` must be https**, except `localhost` — a bearer token must not
  cross a network in the clear.
- **Rates are optional and never guessed.** Omit them and cost is reported as
  untracked rather than as zero; the monthly ceiling is not enforced while it
  cannot be measured.

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
