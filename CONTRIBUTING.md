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
