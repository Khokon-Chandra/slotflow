# Testing

```bash
./vendor/bin/pest
```

```
Tests:    190 passed (626 assertions)
Duration: 4.80s
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

## The one worth reading

[`tests/Concurrency/ConcurrentBookingTest.php`](../tests/Concurrency/ConcurrentBookingTest.php), five tests from cheapest to most convincing.

**1–3. Sequential.** The overlap check rejects an identical slot, rejects a merely overlapping one, and *allows* a booking that starts exactly when the previous one ends. That third case is half-open intervals working: getting it wrong makes back-to-back appointments unbookable, which is quieter than double booking and costs just as much.

**4. The lock is real.** Two genuinely separate MySQL sessions. Connection A opens a transaction and locks the staff row exactly as `BookingService` does; connection B, with `innodb_lock_wait_timeout = 1`, asks for the same lock and must time out. If the lock were advisory, on the wrong row, or absent, B would succeed immediately.

**5. The race.** Four forked processes attempt the identical booking at the same moment against the same database.

```php
$pid = pcntl_fork();

if ($pid === 0) {
    // A brand-new named connection. Two processes writing to one inherited
    // socket corrupts both.
    config()->set('database.connections.child', config('database.connections.mysql'));
    DB::setDefaultConnection('child');

    try   { $bookings->create($data); $outcome = 'won'; }
    catch (SlotUnavailableException) { $outcome = 'lost'; }

    file_put_contents("{$dir}/{$i}", $outcome);
    posix_kill(posix_getpid(), SIGKILL);   // no shutdown handlers in a child
}
```

Exactly one winner, three `409`s, one row in the database.

### Proving the test is not vacuous

A concurrency test that passes whether or not the guard exists is decoration. Comment out the `lockForUpdate()` line and run it:

```
✗ it lets exactly one of several simultaneous requests win the slot
  Expected exactly one winner, got 4. Outcomes: won, won, won, won
```

Four processes, four bookings, one chair. Restore the line and it passes again. That difference is the whole argument for the design.

---

## What the domain tests cover

**[Availability](../tests/Feature/Domain/AvailabilityEngineTest.php)** — 19 tests. The slot grid, closing time, buffers fitting (and not fitting), split shifts, time off, minimum notice, the booking horizon, rules not yet in force, grid re-alignment after an awkward gap, merging several staff diaries.

Plus timezones, which is where the interesting failures live:

- the same instant rendered in two zones,
- a staff member's hours interpreted in *their* zone while the caller reads another,
- the spring-forward window really being an hour shorter,
- the autumn-back window an hour longer,
- and a 09:00 local appointment being `08:00Z` in March and `07:00Z` in April.

The DST tests compare against an ordinary week rather than asserting a hard-coded count, so they are about daylight saving rather than about the slot grid.

**[Risk](../tests/Feature/Domain/NoShowRiskScorerTest.php)** — 12 tests, one per factor plus a determinism test that scores the same booking twice and asserts identical output. That test is the argument for computing the score in PHP.

**[The offline parser](../tests/Feature/Domain/BookingIntentHeuristicTest.php)** — 22 tests over dates, times, service matching and the end-to-end assistant, including that it never writes a booking and that it asks rather than guesses.

**[Tenant isolation](../tests/Feature/TenantIsolationTest.php)** — 8 tests against two fully-seeded workspaces. A leak test against an empty second tenant tests nothing.

**[AI credentials](../tests/Feature/Api/AiSettingsApiTest.php)** — 20 tests. Owner-only access, verify-before-store, and three that exist purely to keep a secret secret: the plaintext key appears in none of the four endpoint responses, the database column holds ciphertext rather than the key, and one workspace's key is invisible and unusable from another.

The verifier is behind an interface for the same reason `AiClient` is — checking a key means calling Anthropic, and this suite makes no network calls and holds no secret.

---

## Static analysis

```bash
./vendor/bin/phpstan analyse   # larastan, level 5 — clean
./vendor/bin/pint --test       # clean
npm run build                  # vue-tsc first; a type error fails the build
```

PHPStan found 51 real issues on its first clean run, and every one was fixed rather than baselined: incomplete model docblocks, redundant null checks, aggregate columns read as model attributes, untyped closures. The two `ignoreErrors` entries that remain are narrow and carry a comment explaining why the analyser is wrong — Laravel's `Seeder::$command` docblock claims non-nullable for a property that is genuinely unset outside the console.

---

## Bugs these tools actually caught

Worth listing, because "we have tests" is a claim and this is evidence.

| Found by | What it was |
|---|---|
| `Model::shouldBeStrict()` | `ServiceResource` lazy-loading `$service->tenant` — one query per row |
| Pest, once a JSON reporter was removed | `BookingWindowException` declared a readonly `$code`, colliding with `Exception::$code`. A **fatal at class load**: every insufficient-notice and booking-horizon path was dead |
| A test expecting 422 | The booking policy was doing the state machine's job, so illegal transitions returned 403 with no explanation |
| The tenant isolation test | `exists:services,id` validated across all tenants — "valid, then 404" is distinguishable from "invalid", which is enough to enumerate |
| A skipped test | An over-wide availability range threw from a constructor and became a 500 instead of a 422 |
| An empty-metrics test | `Collection` offset access without `??` semantics threw on a workspace with no completed bookings |
| Adding customer sign-out | The public header branched admin-or-guest, so a signed-in customer matched neither arm and had no way to log out. The route had worked the whole time; nothing exercised it as a customer |
| Verifying credentials by hand | `.env.example` pinned `SANCTUM_STATEFUL_DOMAINS` to port 8000, so the admin panel 401s on any other port. Laravel's default already derives it from `APP_URL`; the override was worse than no override |

The second one is the reason this project has no custom test reporter. It was swallowing fatal errors and reporting an exit code with no output; removing it made a class-load failure visible immediately.

---

## Writing a new test

```php
use Tests\Support\StudioFactory;

beforeEach(function (): void {
    // Fixed clock. Nothing here should depend on when it runs.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 06:00:00', 'UTC'));

    // Tenant, staff, service, and a tenant bound in TenantContext.
    $this->studio = (new StudioFactory(durationMinutes: 60))->openEveryDay('09:00', '17:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());
```

[`StudioFactory`](../tests/Support/StudioFactory.php) exists because a test that hand-rolls a tenant, a service, a staff member and a week of hours spends most of its lines on setup and buries the assertion.

Almost every model is behind a tenant global scope, so a test that forgets to bind one sees an empty database and a confusing failure. `StudioFactory` binds it; `TestCase::tearDown()` clears it.

There is one custom expectation:

```php
expect($response)->toHaveErrorCode('slot_unavailable');
```
