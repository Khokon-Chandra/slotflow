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
| `staff_has_bookings` | 409 | Cannot delete; reassign first |

404s never name the model class.

### Authentication

Bearer tokens (Laravel Sanctum):

```
Authorization: Bearer 3|xxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Get one from `POST /auth/login` or `POST /auth/register`. `POST /auth/logout` revokes only the token used for that request.

---
