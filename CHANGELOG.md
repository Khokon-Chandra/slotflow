# Changelog

All notable changes to this project are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] — 2026-08-19

First public version.

### Booking

- Availability computed from weekly rules, time off and existing bookings — no `slots` table
- Double-booking prevented by a transaction with a row lock on the staff member, re-checking overlap against committed data before insert
- Per-service turnaround buffers, stored separately from the customer-facing end time
- Full timezone handling: UTC storage, an explicit required `tz` on every rendering endpoint, IANA identifiers only, both daylight-saving transitions covered by tests
- Booking status state machine with enforced transitions and denormalised customer counters
- Human-quotable booking references (`BL-7Q4M2X`) on an alphabet without I, O, 0 or 1

### API

- 32 REST endpoints under `/api/v1`
- One error envelope for every failure, with stable machine-readable codes
- Sanctum bearer tokens; the admin panel is a client of the same API over its session cookie
- OpenAPI 3.1 generated from the code, served at `/docs/api`
- Postman collection with 29 requests and automatic token capture

### AI

- Booking assistant: free text → structured intent → real slots. Never writes
- No-show risk: deterministic score with a full factor breakdown; the model writes only the explanation
- Daily briefing over pre-computed figures
- Service description drafting
- Driver abstraction with a complete deterministic fallback for every task
- Caching, per-tenant rate limits, a monthly spend ceiling, and graceful degradation on any failure
- Every call logged with tokens, latency, cost and outcome; surfaced in an admin usage page

### Multi-tenancy

- `tenant_id` on every business table, enforced by a global scope on read and auto-fill on write
- Tenant-scoped `exists` validation rules, so a foreign id is invalid rather than merely absent
- Tenant resolution from the authenticated user, an `X-Tenant` header, or a query parameter — with a 403 when an authenticated request names a different workspace

### Frontend

- Public booking flow: describe it in plain English, pick a real slot, confirm
- Admin panel: dashboard, filterable diary, services with AI copy drafting, team, weekly hours editor, AI usage, settings
- Vue 3 + Inertia 2 + TypeScript, Tailwind CSS 4 with a token-based light and dark theme
- AI provenance shown on every model-written block

### Quality

- 161 tests, 498 assertions, 4 seconds, against MySQL
- Concurrency suite: row-lock exclusivity plus four forked processes racing for one slot
- PHPStan (larastan) level 5, clean; Laravel Pint, clean
- GitHub Actions across PHP 8.3 and 8.4, with no API key anywhere in the workflow
- `demo:bench` for reproducible performance numbers

### Documentation

- README with the four decisions worth reading
- Architecture, availability algorithm, AI design, API reference, schema, decision records, testing and deployment
