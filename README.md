# Gift Lab — Custom Gifting Platform (B2B v1)

A self-serve platform turning in-house UV + 3D printing into an online B2B
business. Companies browse a no-account catalogue, customise a product on
screen, request a quote, approve a formal proof, issue a PO, and the job flows
into a single shared production queue. Two printer tracks (UV = decorate a
sourced blank; 3D = fabricate from a licensed model) feed the same queue.

**Launch scope: B2B, CORE track spine.** Scraped-UV + 3D-model catalogue breadth
is Phase 2 (schema + strategy interface already in place, guarded off).

## Stack

- **Backend** — Laravel (PHP 8.3), MySQL, decoupled REST + Sanctum cookie auth.
- **Realtime** — Laravel Reverb (websockets). **No polling anywhere.**
- **Frontend** — React + TypeScript (Vite), Zustand, Laravel Echo, Fabric.js designer.
- **Money** — SGD, `decimal(12,2)`. **Time** — UTC in DB, SGT/user-local in UI.

## Layout

```
app/            Enums (state machines), Models, Events (Reverb), Services
                (Pricing, Procurement strategies, Queue, Quote orchestration),
                Http (Controllers, Form Requests, Resources), Policies
database/       Migrations, factories, seeders (pricing config + CORE catalogue)
routes/         api.php (REST), channels.php (broadcast auth)
tests/          Pest — Unit (state machines) + Feature (spine flows)
frontend/       Vite React SPA (stores, pages, Fabric designer) + Vitest tests
deploy/         Nginx, Supervisor (worker + Reverb), production env template
docs/           API.md (endpoints + Reverb), DEPLOYMENT.md (DO Ubuntu runbook)
SECURITY.md     OWASP Top 10 audit + hardening
```

## Core rules (enforced, not conventions)

1. **Two production gates** as state transitions: recorded proof approval, and
   all line items confirmed READY (blank on floor / filament available).
2. **Readiness drives the queue** — FCFS by `ready_at`, not order time.
3. **Scraped data is never authoritative** — procurement-time re-check is the truth.
4. **Product classes are isolated tracks** behind one procurement interface.
5. **Pricing is fully dynamic** — all config in the DB, read at quote time; no
   hardcoded margins/fees; margin floor enforced on amendments.

## Build phases (all shipped)

| Phase | Deliverable |
|-------|-------------|
| 0 | Discovery gate — clarifications + locked decisions |
| 1 | MySQL schema — migrations, factories, seeders |
| 2 | Backend — models, guarded state machines, Reverb events, services |
| 3 | React SPA — designer, Zustand stores, Echo, full spine screens |
| 4 | Tests — Pest (backend) + Vitest/RTL (frontend) |
| 5 | Security — OWASP audit + auth/rate-limit/XSS patches (`SECURITY.md`) |
| 6 | Docs + DevOps — API reference + DigitalOcean LEMP runbook |

## Run it

This repo is a **complete, runnable Laravel app** (not just source).

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
vendor/bin/pest                      # 68 passing
php artisan serve                    # API at http://localhost:8000

cd frontend && npm install && npm run dev   # SPA at http://localhost:5173
```

- **Setup / provisioning** (S3, mail, Stripe, Reverb keys, domains): [`SETUP.md`](SETUP.md)
- **Production deploy** (DigitalOcean LEMP + Reverb + Supervisor + Certbot): [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)
- **API + realtime reference**: [`docs/API.md`](docs/API.md)
- **Security audit**: [`SECURITY.md`](SECURITY.md)

Ships working with fixtures/stubs; provisioning a credential (Thingiverse,
Stripe) auto-switches to the live integration with no code change. Live 3D pull,
B2C Stripe pay-now, artwork upload, and CI (`.github/workflows/ci.yml`) are all
built.
