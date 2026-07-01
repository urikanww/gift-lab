# Gift Lab — Setup & Provisioning

What I've already done for you, and what only you can provision (accounts,
domains, secrets). For the full server runbook see [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

---

## ✅ Already done (in this repo)

- Repo is a **complete, runnable Laravel app** (framework scaffold + our code).
- Dependencies declared in `composer.json` + `composer.lock` (Sanctum, Reverb,
  Pest, Stripe SDK) and `frontend/package.json`.
- Verified locally on a clean SQLite database:
  - `composer install` ✓
  - `php artisan migrate --seed` ✓ (17 migrations; staff users + pricing config + 10 CORE blanks seeded)
  - **Backend: 68 Pest tests, 164 assertions — all passing** ✓
  - **Frontend: 15 Vitest tests, `tsc` clean, `vite build` succeeds** ✓
- Fixtures/stubs are wired by default, so everything runs **without any external
  credentials**. Adding a credential auto-switches to the live integration (no
  code change) — see the toggle notes below.

You do **not** need to run composer/migrate/seed to evaluate locally — it's done.
On your production server you'll run them once (commands in DEPLOYMENT.md).

---

## 🔑 What you must provision

| # | Service | Get | Put in (env) | Enables |
|---|---------|-----|--------------|---------|
| 1 | **Droplet + DNS** | DigitalOcean Ubuntu 24.04; A-records `api.` `app.` `reverb.` | — | Hosting |
| 2 | **MySQL** | DB + user (runbook §4) | `DB_DATABASE/USERNAME/PASSWORD` | Data |
| 3 | **App key** | `php artisan key:generate` | `APP_KEY` | Encryption |
| 4 | **DO Spaces (S3)** | Create a Space + access keys | `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_BUCKET`, `AWS_DEFAULT_REGION`, `AWS_ENDPOINT` | Artwork/proof/model file storage |
| 5 | **Mail (SMTP)** | SES / Postmark / Mailgun / SMTP host | `MAIL_MAILER=smtp`, `MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION`, `MAIL_FROM_ADDRESS` | Quote/proof notifications |
| 6 | **Reverb keys** | `php artisan reverb:install` (or set random strings) | `REVERB_APP_ID/KEY/SECRET`, `BROADCAST_CONNECTION=reverb`; frontend `VITE_REVERB_*` | Realtime (queue/quote/proof push) |
| 7 | **Sanctum/CORS/cookies** | your SPA + API domains | `SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, `SESSION_DOMAIN`, `SESSION_SECURE_COOKIE=true` | SPA cookie auth |
| 8 | **Thingiverse token** *(optional)* | Thingiverse dev app token | `THINGIVERSE_TOKEN` | Live 3D model pull (else stub) |
| 9 | **Cults3D token** *(optional)* | Cults3D API token | `CULTS3D_TOKEN` | (client wiring; stub for now) |
| 10 | **Stripe** *(optional)* | Secret + webhook signing secret | `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | Live B2C pay-now (else fixture) |

All keys are listed in [`.env.example`](.env.example) (dev) and
[`deploy/.env.production.example`](deploy/.env.production.example) (prod).

---

## 🔀 Feature toggles (how fixtures become live)

The app ships working with fixtures; provisioning a credential flips it live with
**no code change** (`app/Providers/AppServiceProvider.php` decides at runtime):

- **3D model pull** — set `THINGIVERSE_TOKEN` → live Thingiverse client (CC0/CC_BY
  gate applied automatically); unset → stub fixtures.
- **B2C pay-now** — set `STRIPE_SECRET` → Stripe Checkout + webhook; unset →
  fixture gateway (captures immediately, for dev/test). Also **enable the flow**
  by flipping the DB config row: `pricing_configs` group `config`, key
  `pay_now_cutoff` → `{"mode":"pay_now","b2c_enabled":true}` (via a superadmin
  data update / tinker). Point Stripe's webhook at `POST /api/stripe/webhook`.
- **Marketplace ingest (scraped-UV)** — ingest is a human/admin/contracted-feed
  adapter, **not** a bot checkout (ToS). Bind your feed to the `ScraperClient`
  interface; the completeness gate, drift, and per-order re-check all work today
  against the fixture.

---

## 🖥️ Commands you run once on the server (I can't reach your infra)

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env        # then fill in the secrets above
php artisan key:generate
php artisan migrate --force --seed
php artisan config:cache route:cache
# frontend:
cd frontend && npm ci && npm run build   # deploy dist/ (see DEPLOYMENT.md)
```

⚠️ **Change the seeded staff passwords immediately**: `superadmin@giftlab.local`
and `ops@giftlab.local` (default `ChangeMe!123`).

Full LEMP + Reverb + Supervisor + Certbot + cron steps: [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).
