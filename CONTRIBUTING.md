# Contributing

This is a portfolio project, so it is unlikely to take feature pull requests — but it is meant to be read, run and taken apart. If something is wrong, an issue is welcome.

---

## Running it

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate

docker compose up -d            # MySQL, Redis, Mailpit
php artisan demo:seed --fresh

php artisan serve
npm run dev
```

No `ANTHROPIC_API_KEY` needed. Add one to `.env` to exercise the Claude path.

## Before you push

```bash
./vendor/bin/pest              # 161 tests, ~4s, needs MySQL
./vendor/bin/phpstan analyse   # level 5, must be clean
./vendor/bin/pint              # formats in place
npm run build                  # type-checks, then builds
```

The test suite needs a `slotflow_testing` database. `docker compose up -d` creates it; otherwise:

```bash
mysql -e "CREATE DATABASE slotflow_testing"
```

## House style

- **`declare(strict_types=1)` everywhere.** Enforced by Pint.
- **Business rules go in `app/Domain`.** A controller validates, authorises, calls one domain service, renders a resource.
- **Comments explain *why*.** What the code does is visible in the code. Why a lock is on the staff row rather than the booking range is not.
- **Every AI task needs a heuristic implementation.** No exceptions — it is what makes the project runnable and CI free of secrets.
- **New behaviour needs a test that fails without it.** For the concurrency guard specifically, the bar is a test that fails when the lock is removed.
- **No baselines.** If PHPStan finds something, fix it or add a narrow, commented ignore that explains why the analyser is wrong.

## Commits

Conventional-commit prefixes, one concern per commit:

```
feat(availability): support overnight shifts
fix(booking): reject overlapping slots when a buffer applies
test(concurrency): assert the row lock is exclusive
docs(ai): explain the fallback driver
```

The history is meant to be readable. Squash the exploration; keep the decisions.
