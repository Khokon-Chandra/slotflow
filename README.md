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
