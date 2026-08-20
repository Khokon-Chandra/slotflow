# Deployment

Nothing exotic. It is a Laravel application with a Vite build and a MySQL database.

---

## Requirements

| | |
|---|---|
| PHP | 8.3 or 8.4 — `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `tokenizer`, `xml`, `intl`, `bcmath` |
| MySQL | 8.0+ (`SELECT … FOR UPDATE` and `utf8mb4` are both load-bearing) |
| Node | 22+ at build time only |
| Redis | Optional but recommended for cache and queue |

---

## First deploy

```bash
git clone <repo> && cd slotflow

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

Point the web root at `public/`.

---

## Environment

The settings that matter beyond the Laravel defaults:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=UTC            # leave it. Everything is stored in UTC by design

DB_CONNECTION=mysql
CACHE_STORE=redis           # availability caching is much better served by Redis
QUEUE_CONNECTION=redis

# Usually unset: Laravel derives this from APP_URL, including the port, and
# adds localhost. Set it only when the browser reaches the app on a different
# host from APP_URL — a reverse proxy, or a separate frontend domain.
# SANCTUM_STATEFUL_DOMAINS=admin.example.com

# --- AI ---------------------------------------------------------------------
AI_DRIVER=auto              # a provider when one resolves, heuristic otherwise
AI_PLATFORM_PROVIDER=anthropic
ANTHROPIC_API_KEY=
AI_MODEL=claude-opus-5
AI_EFFORT=low
AI_MONTHLY_BUDGET_USD=25    # a soft ceiling; crossing it degrades, never breaks
AI_CACHE_TTL=900
```

**`AI_DRIVER=auto` with no key is a valid production configuration.** Every feature works; the assisted ones are simply plainer. Start there, add a key when you want the difference, and watch `/admin/ai` to see what it costs.

`ANTHROPIC_API_KEY` is the *platform* credential — used by any workspace that has not connected its own. An owner connects a provider at **Admin → AI providers** (Anthropic, OpenAI, DeepSeek, or any OpenAI-compatible endpoint), which takes precedence and bills their own account. A single-tenant deployment can ignore that entirely and set `.env` once.

⚠️ **Workspace keys are encrypted with `APP_KEY`.** Rotating it without re-encrypting makes them unreadable — those workspaces fall back to the platform key, or to the built-in implementations, until someone re-enters theirs. Rotate deliberately, not incidentally.

`APP_TIMEZONE` should stay `UTC`. All instants are stored in UTC, and every rendering timezone is explicit — the tenant's, the staff member's, or the caller's. Changing it would not help and would make one of those three quietly wrong.

---

## The scheduler

One cron entry:

```cron
* * * * * cd /path/to/slotflow && php artisan schedule:run >> /dev/null 2>&1
```

It runs:

| | |
|---|---|
| `slotflow:rescore --days=30` | Nightly at 03:00. Risk scores drift as an appointment approaches and as customers build a history; recomputing keeps the morning briefing honest |
| `sanctum:prune-expired` | Daily. Unused tokens are attack surface |

Both use `withoutOverlapping()` and `onOneServer()`, so a multi-server deployment is safe.

---

## The queue

Nothing in the booking path is queued today — it all completes inside the request, and the transaction is short by design. The queue connection is configured for when reminders and confirmation emails land.

If you enable it:

```bash
php artisan queue:work --tries=3 --max-time=3600
```

---

## Health check

`/up` — Laravel's built-in endpoint. Add it to your load balancer.

---

## Zero-downtime notes

- **Migrations here are additive.** None drops or renames a column, so a rolling deploy is safe.
- **`config:cache` must run after the environment is in place.** A cached config with a missing key fails at boot rather than at first use.
- **The availability cache is keyed by a version counter**, so a deploy does not need to flush it. If you flush anyway, the first request per tenant simply recomputes.

---

## Scaling, in the order it would actually matter

1. **`CACHE_STORE=redis`.** Availability caching is the highest-leverage change, and the database cache store is the wrong tool for a 60-second TTL.
2. **A read replica for the admin diary.** The metrics aggregation and the booking list are read-heavy and tolerate a second of lag. The booking guard must stay on the primary — a lock on a replica is not a lock.
3. **Partition `bookings` by year.** Only once the table passes single-digit millions. The diary index handles far more than a small business will ever produce.
4. **A materialised availability view.** Last, not first, and only if the measured cache hit rate says so. See [DECISIONS.md § 2](DECISIONS.md).

---

## Backups

`bookings`, `customers` and `booking_risk_assessments` are the ones that hurt to lose. Standard `mysqldump` or managed snapshots are fine; nothing in the schema needs special handling.

`ai_interactions` is an audit log — worth keeping for cost analysis, safe to prune beyond a year.
