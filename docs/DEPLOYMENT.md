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

SANCTUM_STATEFUL_DOMAINS=your-domain.com

# --- AI ---------------------------------------------------------------------
AI_DRIVER=auto              # Claude when a key is set, heuristic otherwise
ANTHROPIC_API_KEY=
AI_MODEL=claude-opus-5
AI_EFFORT=low
AI_MONTHLY_BUDGET_USD=25    # a soft ceiling; crossing it degrades, never breaks
AI_CACHE_TTL=900
```

**`AI_DRIVER=auto` with no key is a valid production configuration.** Every feature works; the assisted ones are simply plainer. Start there, add a key when you want the difference, and watch `/admin/ai` to see what it costs.

`APP_TIMEZONE` should stay `UTC`. All instants are stored in UTC, and every rendering timezone is explicit — the tenant's, the staff member's, or the caller's. Changing it would not help and would make one of those three quietly wrong.

---
