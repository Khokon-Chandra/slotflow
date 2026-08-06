# Testing

```bash
./vendor/bin/pest
```

```
Tests:    161 passed (498 assertions)
Duration: 4.07s
```

---

## How it is organised

| Suite | Database | Covers |
|---|---|---|
| `tests/Unit` | none | Interval arithmetic, the booking state machine. Pure functions, microseconds |
| `tests/Feature` | `RefreshDatabase` | Domain services and every endpoint group |
| `tests/Concurrency` | `DatabaseTruncation` | Row locks and forked processes |

The concurrency suite truncates rather than transacts. `RefreshDatabase` wraps each test in a transaction and rolls it back — fast and perfectly correct, but nothing is ever committed, so a second connection or a forked process cannot see the fixtures. The one test that matters most needs real committed rows and real concurrent connections, so it pays the cost.

---

## Against MySQL, on purpose

SQLite in memory is the Laravel default and much faster. It also has no `SELECT … FOR UPDATE`.

The double-booking guard is the most important behaviour in this application. On SQLite the test for it would pass without testing anything, which is worse than having no test — a green suite that proves nothing is a suite you trust wrongly.

So the whole suite runs on MySQL, in CI too. It costs three seconds and buys the guarantee that every migration, index and constraint is exercised on the engine that will run in production.

```bash
mysql -e "CREATE DATABASE slotflow_testing"   # or docker compose up -d
```

---

## No network, ever

`phpunit.xml` sets `AI_DRIVER=heuristic` and an empty `ANTHROPIC_API_KEY`.

That constrains nothing, because the heuristic driver is a real implementation rather than a stub — the same code path that serves the demo when no key is configured. So the AI features are genuinely under test, CI needs no secret, and running the suite never costs money.

The Claude path is covered by binding a client that throws and asserting the application degrades correctly, which is the behaviour that actually matters:

```php
it('serves an answer when the AI client throws', function () {
    $this->app->bind(ClaudeClient::class, fn () => new class implements AiClient {
        public function run(AiRequest $request): AiResponse { throw new RuntimeException('API is down'); }
        public function name(): string { return 'claude'; }
    });

    config()->set('ai.driver', 'claude');

    $this->postJson('/api/v1/ai/booking-assistant', [...])
        ->assertOk()
        ->assertJsonPath('data.ai.driver', 'heuristic')
        ->assertJsonPath('data.ai.degraded_reason', 'api_error');
});
```

---
