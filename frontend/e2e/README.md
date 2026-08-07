# E2E journeys (Layer 1)

Full-stack Playwright journeys: real SPA (`:5173`) → real Laravel API (`:8000`) → DB.
Unlike the PHPUnit/vitest suites, these exercise the seams no other layer sees
(frontend↔API contract, permission gate vs. API enforcement, cross-page flows).

**These specs write data** (they place quotes). Point them at an isolated e2e
database — never your working dev DB.

## One-time setup

```bash
# 1. Playwright + browser
cd frontend
npm install
npx playwright install chromium

# 2. Isolated e2e env for the Laravel app (from repo root)
cd ..
cp .env.ci .env.e2e
touch database/e2e.sqlite
php artisan key:generate --env=e2e
```

Then edit `.env.e2e` (these three differ from `.env.ci`, which is tuned for
in-process PHPUnit, not a live HTTP server):

```ini
DB_DATABASE=database/e2e.sqlite   # its own DB file, not the dev DB
SESSION_DRIVER=file               # array sessions don't persist across HTTP requests -> login can't stick
ADMIN_SEED_PASSWORD=ChangeMe!123  # forces a known staff password (env is "e2e", not local/testing, so the seeder would otherwise randomise it)
```

`SANCTUM_STATEFUL_DOMAINS` already includes `localhost:5173` (inherited from
`.env.ci`), so the SPA's cross-port cookie auth works.

`.env.e2e` and `database/e2e.sqlite` are gitignored — each dev creates their own.

## Run

```bash
cd frontend
npm run e2e          # headless
npm run e2e:ui       # Playwright UI mode (watch/debug)
npm run e2e:report   # open last HTML report
```

`globalSetup` runs `migrate:fresh --seed --seeder=E2eSeeder` under `APP_ENV=e2e`
before the suite, so every run starts from the same known fixtures. Set
`E2E_SKIP_SEED=1` to reuse the current DB while iterating on specs.

Playwright starts both servers itself. The API server is **never reused**
(`reuseExistingServer: false`): reusing whatever sits on `:8000` risks silently
testing against the dev MySQL DB. **Kill stray dev servers on 8000 first**, or
the run errors with "port in use". On Windows, `pkill` from Git Bash does not
kill native `php.exe`/`node.exe` — use `taskkill //F //IM php.exe`.

Two dev-server realities the config works around, both because `php artisan
serve` is single-threaded:
- Concurrent SPA requests serialize, so `expect` timeout is raised to 15s.
- `loginAs` waits for `networkidle` before submitting, so login's csrf+POST
  don't race the mount-time `/user` probe into a 419 "CSRF token mismatch".
- CI (Linux) also gets `PHP_CLI_SERVER_WORKERS=4` for real concurrency; it's a
  no-op on Windows (no fork).

## Fixtures

Seeded by `database/seeders/E2eSeeder.php`, mirrored in `fixtures/roles.ts` +
`fixtures/data.ts` — **change the PHP and TS sides together**:

| Role         | Email                       | Password       | Lands on   |
| ------------ | --------------------------- | -------------- | ---------- |
| buyer        | `buyer.e2e@giftlab.local`   | `E2ePass!123`  | `/account` |
| staff_admin  | `ops@giftlab.local`         | `ChangeMe!123` | `/dashboard` |
| superadmin   | `superadmin@giftlab.local`  | `ChangeMe!123` | `/dashboard` |

Plus one quotable product (`E2E Fixture Mug`, PUBLISHED CORE + variant).

## The pattern (extend this)

`journeys/login-checkout.spec.ts` is the template: **write a journey once, loop
it over `ROLES`**, and assert role-appropriate outcomes (buyer places a quote;
staff must NOT fall into a buyer checkout). Copy the shape for the next journeys.

Known fragility: selectors lean on visible text/roles because the app has almost
no `data-testid`. If a journey breaks on a copy change, prefer adding a
`data-testid` to the source element over loosening the selector.

## CI

Not wired into `.github/workflows/ci.yml` yet — it needs a job that boots PHP +
Node together and installs the Playwright browser. Add once these run green
locally.
