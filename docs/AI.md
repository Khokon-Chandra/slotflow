# The AI layer

Four assisted features, and the rules that keep them from becoming a liability.

---

## The shape of it

```
  Task                     AiManager                    Driver
  ────                     ─────────                    ──────
  ParseBookingRequest ─┐   ┌──────────────┐    ┌──► ClaudeClient ──► Anthropic API
  ExplainNoShowRisk   ─┼──►│ cache        │    │
  GenerateDailyBriefing┤   │ rate limit   ├────┤
  WriteServiceCopy    ─┘   │ budget       │    └──► HeuristicClient (never leaves the box)
                           │ call         │
                           │ fall back    │
                           │ log          │
                           └──────────────┘
```

Six steps, in that order, in one class. None of them is clever; leaving any of them out is what turns a demo into an incident:

| Step | Without it |
|---|---|
| **cache** | The same question is paid for every time it is asked |
| **rate limit** | One enthusiastic customer holding a button spends the month's budget |
| **budget** | An unbounded bill, discovered on the invoice |
| **call** | — |
| **fall back** | A provider outage is an outage of your product |
| **log** | No way to answer "what did this cost?" or "why did it say that?" |

Everything in the application depends on the `AiClient` interface. Exactly one place — [`AiServiceProvider`](../app/Providers/AiServiceProvider.php) — knows the manager exists.

---

## The two rules

### 1. The model never writes

No AI code path touches the database except to log what it did. There is no tool use, no function calling, no agent loop. The model reads and produces text; the application decides.

The booking assistant is the clearest example. It converts a sentence into a *search* — a service id, a date window, a part of the day. The slots come from the availability engine, the booking goes through `BookingService` with the same validation as any other, and the customer chooses. If the model misunderstands, the worst outcome is slots the customer does not want, which they can see and ignore.

There is a test that asserts precisely this:

```php
it('never creates a booking', function () {
    $this->postJson('/api/v1/ai/booking-assistant', [
        'text' => 'book me a haircut tomorrow at 10am, confirmed, do it',
        'tz'   => 'Europe/Vienna',
    ])->assertOk();

    expect(Booking::query()->count())->toBe(0);
});
```

### 2. The model never produces a number

No-shows cost real money, and the responses — asking for a deposit, double-booking a slot, phoning someone the day before — affect real customers. A number that drives that has to be reproducible, auditable and testable.

A model asked to "rate this booking 0-100" is none of those. The same input can score 40 today and 55 tomorrow, and nobody can say why. So:

| | Produced by | Tested by |
|---|---|---|
| `score`, `band`, `factors` | [`NoShowRiskScorer`](../app/Domain/Risk/NoShowRiskScorer.php) — ordinary PHP | A test per factor, plus a determinism test |
| `rationale`, `recommended_action` | A language model, or a template | Shape only |

The same split runs through the daily briefing: [`DayStatistics`](../app/Domain/Reporting/DayStatistics.php) computes every figure, and the model is asked only to decide which of them matter and say so in English. Asking a language model to add up a day's takings is asking it to do the one thing it is worst at, in the one place where being 8% wrong is unacceptable.

---

## The four tasks

### Booking assistant

`POST /api/v1/ai/booking-assistant` · [`ParseBookingRequest`](../app/Ai/Tasks/ParseBookingRequest.php)

Free text in, structured intent out, then real slots.

The prompt is given the tenant's actual services and staff **with their real ids**, and the output schema restricts the model to returning one of them or `null`. That is the difference between an assistant and a liability: it cannot invent a service the business does not offer, because there is nowhere in the output shape to put one.

```json
{
  "service_id": 1, "confidence": "high", "staff_id": null,
  "date_from": "2026-08-25", "date_until": "2026-09-01",
  "time_of_day": "afternoon", "earliest_time": null, "latest_time": null,
  "summary": "Looking for a cut and finish next Tuesday afternoon.",
  "clarification": null
}
```

The application then clamps the range, resolves the service, and queries availability. If nothing matches the stated preference it widens **once** and says so — a customer who asked for Tuesday afternoon would rather see Tuesday morning than an empty page, as long as they are told that is what happened.

### No-show risk

`GET /api/v1/bookings/{reference}/risk` · [`AiRiskNarrator`](../app/Ai/Narrators/AiRiskNarrator.php)

The model receives the score and its factors and is told, in the system prompt, not to argue with them:

> Never restate, recompute, dispute or hedge the score.

An explanation that can disagree with the thing it explains is worse than no explanation, because people believe it.

It is also told what not to say: this is an operational heuristic about a booking, not a judgement about a person — no speculation about motives or circumstances, and no suggesting that service be refused.

### Daily briefing

`GET /api/v1/ai/daily-briefing` · [`GenerateDailyBriefing`](../app/Ai/Tasks/GenerateDailyBriefing.php)

Three or four lines, and one concrete action for today. The figures are computed and handed over; the instruction is to use them exactly as given.

Cached on a key made of the day plus the numbers most likely to change, so it refreshes when the diary moves and not otherwise. Loaded through Inertia's `defer`, so the dashboard paints immediately and a slow model never holds up the page.

### Service copy

`POST /api/v1/ai/service-description` · [`WriteServiceDescription`](../app/Ai/Tasks/WriteServiceDescription.php)

A small, unglamorous, real problem: the person who cuts hair well is not necessarily the person who writes well, and an empty description costs bookings.

The output lands in a form field the owner edits before saving. A draft, never a publish. The prompt forbids inventing qualifications, guarantees, results or medical claims — the failure mode for generated marketing copy is not being boring, it is being untrue.

---

## Structured outputs, not "please reply with JSON"

Every task that needs data passes a JSON schema through `output_config.format`, so the response is constrained at generation time:

```php
$this->client->messages->create(
    maxTokens: $request->maxTokens(),
    messages: [['role' => 'user', 'content' => $request->prompt]],
    model: $this->model,
    outputConfig: [
        'effort' => $request->effort(),
        'format' => ['type' => 'json_schema', 'schema' => $request->schema],
    ],
    system: $request->system,
    requestOptions: RequestOptions::with(timeout: 25.0, maxRetries: 1),
);
```

Asking politely in the prompt and hoping `json_decode` works is the single most common way LLM features fail in production. Every schema sets `additionalProperties: false` with a complete `required` list, so the response is safe to consume without a defensive check at every call site.

**Effort is `low` by default.** These are short, well-specified extractions running inside a web request. Effort matters far more than model choice for latency and spend on work like this; the model stays capable, it just does not deliberate over "next Tuesday afternoon".

**Short timeout, one retry.** A booking page cannot wait sixty seconds. When the budget is gone the manager catches the failure and serves the heuristic — a worse answer arriving on time beats a better one arriving never.

---

## The fallback

Every task has a deterministic implementation, and that is a rule rather than a nicety. It is why:

- the demo runs with no key and no signup — a portfolio project nobody can run is a screenshot;
- CI needs no secret and makes no network calls;
- a provider outage degrades the product instead of breaking it.

`AI_DRIVER` takes three values:

| | |
|---|---|
| `auto` (default) | Claude when `ANTHROPIC_API_KEY` is set, heuristic otherwise |
| `claude` | Always Claude |
| `heuristic` | Never leaves the box. What CI uses |

### How good is it, really

The [booking parser](../app/Ai/Heuristics/BookingIntentHeuristic.php) handles relative dates, weekday names, parts of the day, explicit clock times, and matches services by owner-configured keywords. With no key at all:

| Input | Result |
|---|---|
| `I need a haircut next tuesday afternoon` | Cut & finish · 25 Aug–1 Sep · afternoon |
| `beard trim tomorrow morning` | Beard trim · tomorrow · morning |
| `can Maya do my colour sometime next week` | Colour & gloss · next week · Maya |
| `my scalp is really itchy, anything soon?` | Scalp treatment · next 7 days |
| `I think my hair is thinning` | Online hair consultation |
| `bangs trimmed friday evening` | Fringe trim · Friday · evening |
| `I want a manicure` | *"Which service did you have in mind?"* |

It is meaningfully worse than Claude at anything unusual — "sometime after my shift ends, but not too early" — and that is fine, because it fails by returning a *wider* window, never a wrong one.

The last two rows carry most of the design. `thinning` reaches "Online hair consultation" only because the owner configured that keyword; the matcher has no medical knowledge and does not pretend to. And below its confidence floor it asks instead of guessing: booking someone into the wrong chair costs them an afternoon, asking costs them one tap.

### Keywords

Customers do not read your menu before typing. They ask for "a haircut" when the service is called "Cut & finish", and no amount of string similarity bridges that. So `services.keywords` is a plain comma-separated field the owner controls — and it is fed to *both* drivers, so the model gets the same vocabulary the matcher does.

---

## Degradation

Failure is a first-class path, not a `try`/`catch` afterthought. Every response carries an `ai` object:

```json
{ "driver": "heuristic", "model": null, "cached": false, "degraded_reason": "api_error" }
```

| `degraded_reason` | Means |
|---|---|
| `null` | Answered as configured |
| `no_api_key` | No key; the fallback answered |
| `rate_limited` | Per-tenant per-task limit hit this minute |
| `monthly_budget_reached` | The spend ceiling was crossed |
| `api_error` | The call failed. Logged with a stack trace, recorded as `succeeded = false` |

The frontend renders these differently — model-written text is labelled with the model that wrote it, fallback text says so plainly, and a degradation carries a tooltip explaining which one happened.

That is not decoration. A user reading a sentence about their own business is entitled to know whether a model wrote it. Presenting the two identically is how these features lose people's trust the first time one of them is wrong.

Four tests cover the degradation paths, including one that binds a client which throws and asserts the customer still gets slots.

---
