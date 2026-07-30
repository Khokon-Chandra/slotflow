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
