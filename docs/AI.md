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

## Providers

A workspace connects a provider at **Admin → AI providers**. The catalogue is
configuration, not code:

| Provider | Driver | Structured output |
|---|---|---|
| **Anthropic** | Official SDK | JSON schema, enforced at generation |
| **OpenAI** | OpenAI Chat Completions over HTTP | JSON schema, enforced |
| **DeepSeek** | Same driver, different base URL | JSON mode only — schema goes in the prompt |
| **Custom** | Same driver, workspace-supplied URL | JSON mode only |

That last row is the point of the design. Groq, Together, Mistral, xAI,
OpenRouter, Ollama and LM Studio all speak `POST {base}/chat/completions` with
the same body, so connecting one is a name, a URL and a key — no new class, no
new dependency, no release. Adding a provider *to the catalogue* is an entry in
`config/ai.php`.

There are two drivers, and there are meant to be two:

- [`AnthropicDriver`](../app/Ai/Drivers/AnthropicDriver.php) — its request and
  response shapes differ, and the official SDK is worth having for the provider
  this application defaults to.
- [`OpenAiCompatibleDriver`](../app/Ai/Drivers/OpenAiCompatibleDriver.php) —
  raw HTTP against the wire format rather than any single vendor's SDK. A
  package per provider would be a dependency per provider; the shape itself is
  small, stable and shared.

### Structured output is not uniform, and the code says so

Where a provider supports it, the schema is sent as `response_format.json_schema`
and generation is constrained to it. Where it does not — DeepSeek, most
self-hosted runtimes — the driver falls back to `response_format:
{type: "json_object"}`, which guarantees syntactically valid JSON but not the
right shape, and puts the schema in the prompt.

The second path is genuinely weaker, and `supports_json_schema` is part of the
provider contract rather than a footnote. The driver also strips code fences on
that path, because providers wrap JSON in them despite being asked not to.

### Resolution

| Source | Set where | Who pays |
|---|---|---|
| **Workspace** | Admin → AI providers | The business |
| **Platform** | `ANTHROPIC_API_KEY` in `.env` | Whoever runs the deployment |
| Neither | — | Nobody. The heuristic driver answers |

Exactly one credential is in force per workspace at a time. Connecting the
first makes it active; disconnecting the active one promotes the next verified
credential rather than leaving a workspace with keys and none in use.

### Credentials are verified before they are stored

Connecting calls the provider first — `models.retrieve` on Anthropic,
`GET {base}/models` elsewhere. Both validate the credential and the model, cost
no tokens, and return fast enough for the form to be synchronous.

If the check fails, **nothing is written**. Storing an unverified key would be
worse than storing none: the workspace would look configured, every call would
quietly fall back, and the only evidence would be a `degraded_reason` nobody
was reading.

Whether the wanted model appears in a `/models` listing is treated as a *hint*,
not a verdict — gateways and self-hosted runtimes routinely return partial
lists, and refusing a working credential over an incomplete listing is worse
than accepting one that later 404s, which the driver reports anyway.

There is also a **re-check** action, because a credential that verified on
Tuesday can be revoked on Wednesday. `last_check_passed` is worded as *when it
was last known good*, not as a claim about now.

### How keys are kept

- **Encrypted at rest** with Laravel's `encrypted` cast, which uses `APP_KEY`.
  ⚠️ Rotating `APP_KEY` without re-encrypting makes stored keys unreadable;
  workspaces fall back to the platform credential or the heuristic driver until
  someone re-enters theirs.
- **Never returned.** No endpoint has a branch that can emit one — only
  `…Ab12`. A test asserts the plaintext appears in none of four responses.
- **Never logged.** The verifier catches exceptions and logs the class, the
  provider and the model, never the credential. The HTTP driver never logs a
  response body, which can echo the request.
- **Not mass-assignable.** `api_key` is absent from `Fillable`; the only path is
  [`StoreProviderCredential`](../app/Ai/Credentials/StoreProviderCredential.php).
- **Owner only.** Staff use every AI feature and read the usage page, and see
  nothing about the credential that pays for it — the same line a payment
  method sits on.
- **https only**, except `localhost`. A bearer token must not cross a network
  in the clear; a runtime on the same machine is a different matter.

### One client per key, never one client shared

The Anthropic SDK client is built by
[`AnthropicClientFactory`](../app/Ai/Credentials/AnthropicClientFactory.php)
and cached **keyed by the key itself**. Constructing it runs PSR-18 discovery,
so rebuilding per call is wasteful — but a single cached client handed to every
workspace would send one tenant's request on another's credential, and nothing
would say so. Keying by the key makes that impossible rather than unlikely, and
the map is keyed by a hash so a memory dump does not spill credentials.

Both drivers resolve credentials **per call**, never at construction. A queue
worker handles jobs for several workspaces in one process.

---

## Cost, and the refusal to guess it

Rates are USD per million tokens, and they exist so this application can
estimate spend and enforce a ceiling.

**Where a rate is unknown, it stays unknown.** The catalogue ships published
rates for Anthropic models and none for the rest, because a number that was
right when this file was written and wrong by the time you read it is worse
than no number: a wrong rate produces a confident, incorrect estimate, and
nobody re-checks a figure that looks plausible.

So an unpriced model reports cost as **untracked**, never as zero — the two
look identical in a sum and mean opposite things. The admin panel says so, asks
for the rates, and links to the provider's pricing page. Enter them and spend
tracking and the ceiling start working.

The monthly ceiling is not enforced while spend is untracked. A limit measured
against an unmeasured cost is not a limit, and pretending otherwise is how a
budget guard silently stops working.


Beyond that, spend is bounded three ways.

**A monthly ceiling.** `AI_MONTHLY_BUDGET_USD` (default 25). Crossing it stops the API calls and serves the fallback until the month rolls over. Nothing breaks; answers get simpler. That is the right failure mode for a spend limit, and the difference between a bad morning and a bad quarter.

**Per-tenant, per-task rate limits.** Configured in `config/ai.php`. The unauthenticated booking assistant is the tightest, because it is the only public route that costs money.

**Caching.** 15 minutes by default, keyed per task. Two customers asking the same thing on the same day share one call; two bookings with the same score and the same factors share one rationale.

Cost is recorded per call in **micro-dollars as an integer**, so a month of rows sums without floating-point drift. Rates live in `config/ai.php` and are used both for the budget guard and the admin usage panel.

---

## Observability

Every call writes an [`AiInteraction`](../app/Models/AiInteraction.php): task, driver, model, tokens in and out, cached tokens, cost, latency, success, whether it came from cache, and the failure reason.

The admin **AI usage** page reads it: calls and spend by task, cache hit rate, failure count, budget consumed, and the last 25 calls individually.

Without that table an "AI feature" is a black box you cannot budget for, debug, or explain to a client. This page exists before the spend does.

The write is wrapped in a `try`/`catch` — observability must never be the thing that takes a request down.

---

## Prompt hygiene

- **Never interpolate user text into a system prompt.** The customer's sentence is the user message; the business's data is in the system prompt. Mixing them is prompt injection with extra steps.
- **Input is capped.** 400 characters on the booking assistant. It is a booking request, not a conversation, and a cap is the cheapest defence against someone using an unauthenticated endpoint as free inference.
- **Output is constrained by schema**, not by instruction.
- **Model ids are complete as written.** `claude-opus-5`, never with a date suffix appended.
- **No customer PII beyond the first name** reaches the model in the risk narrator, and none at all in the copy writer.

---

## What is deliberately not here

- **No conversational memory.** Each call is independent. A booking assistant that remembers is a support burden, not a feature.
- **No tool use or agent loop.** The model narrows a search; the application does the rest. See rule 1.
- **No streaming.** These outputs are short and land in a form field or a card. Streaming would add complexity for no user-visible gain.
- **No fine-tuning or embeddings.** Six services do not need a vector store; a keyword field the owner controls beats one, and they can edit it.

---

Next: [DECISIONS.md](DECISIONS.md) — the decision records, including the ones that were rejected.
