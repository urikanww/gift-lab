# Gift-Lab — Go-Live Checklist

**Date:** 2026-07-29 · **Method:** verified against current code/config (not a paper list). Each item is ✅ done, ⚠️ **action required before launch**, or 📋 a manual deploy step (see `docs/DEPLOYMENT.md`).

Legend — ✅ verified in-repo · ⚠️ must be actioned · 📋 runbook/manual step.

---

## 1. Code & tests

| # | Item | Status |
|---|------|--------|
| 1.1 | Backend suite green | ✅ **1048 passing, 0 failing.** The previously-failing 4 courier tests are fixed at the source — `phpunit.xml` now blanks `NINJAVAN_CLIENT_ID/SECRET/WEBHOOK_SECRET` so the FixtureNinjaVanClient always binds (a developer's live `.env` creds no longer leak into tests). |
| 1.2 | Frontend suite green | ✅ **466 passing**, `tsc --noEmit` clean. |
| 1.3 | Full flow-audit register resolved | ✅ All High/Med/Low findings fixed or reviewed-and-kept-by-design — see `FINDINGS.md` fix log (Batches 1–6). |
| 1.4 | `composer audit` / `npm audit` | 📋 Run in CI before each release (`docs/DEPLOYMENT.md` §13). Not run here. |

## 2. Secrets & configuration ⚠️ (the critical section)

| # | Item | Status |
|---|------|--------|
| 2.1 | **Rotate the live secrets currently in local `.env`** | ⚠️ **BLOCKER.** The local `.env` holds real DigitalOcean Spaces keys, a real Gmail app password, and NinjaVan creds in plaintext. Rotate all three and move them to the server's `.env` / a secret store **before this repo or environment is shared**. `.env` is correctly git-ignored (`.gitignore:13`), so nothing is committed — but the live values must still be rotated. |
| 2.2 | `.env` never committed | ✅ `/.env`, `/.env.production`, `frontend/.env` are git-ignored; `git ls-files` confirms `.env` is untracked. |
| 2.3 | Production env template complete | ✅ `deploy/.env.production.example` now covers the payment/courier/webhook/artwork keys it was missing (`STRIPE_*`, `NINJAVAN_*`, `NINJAVAN_WEBHOOK_SECRET`, `ARTWORK_DISK=spaces_private`). |
| 2.4 | `APP_DEBUG=false`, `APP_ENV=production` | ✅ Set in the prod template. Verify on the box with `php artisan about`. |
| 2.5 | **`NINJAVAN_BASE_URL` = production host** | ⚠️ Defaults to **sandbox** (`api-sandbox.ninjavan.co`). If creds are set but base_url is sandbox/unset, the app refuses to bind (won't silently book fake parcels) — but you must set `https://api.ninjavan.co/sg` to actually dispatch. |
| 2.6 | `NINJAVAN_WEBHOOK_SECRET` set | ⚠️ Inbound webhook **fails closed (401)** without it — no delivery status would ever reach the app. Required if using NinjaVan. |
| 2.7 | `STRIPE_WEBHOOK_SECRET` set (only if pay-now is used) | ⚠️ Stripe webhook fails closed without it. Pay-now (B2C) is **off by default** (`pay_now_cutoff.b2c_enabled=false`), so this is only needed if you enable card payment. |
| 2.8 | `ARTWORK_DISK=spaces_private` | ⚠️ Defaults to `local`; without it, public designer uploads write to the server filesystem instead of private Spaces. |
| 2.9 | `SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS` match the real app+api hosts | 📋 Template has placeholders. **Host mismatch breaks the auth cookie** (this was the "login broken" false alarm during testing — `localhost` vs `127.0.0.1`). Get these exactly right. |

## 3. Infrastructure (per `DEPLOYMENT.md`)

| # | Item | Status |
|---|------|--------|
| 3.1 | Queue worker running (Supervisor) | 📋 Required — all mailables are `ShouldQueue`; without a worker, no emails send. `deploy/supervisor/giftlab-worker.conf`. |
| 3.2 | `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` | ✅ In prod template. Note: the scheduler's `onOneServer()` **requires a shared (redis/database) cache** — array won't do. |
| 3.3 | Scheduler cron (`schedule:run`) | ✅ Tasks defined in `routes/console.php`: catalogue resync/discover/slice, artwork prune, gift-ideas refresh, **`quotes:chase`** (chase ladders), `quotes:expire-drafts`, `queue:prune-failed`. Needs the `* * * * *` cron entry (`DEPLOYMENT.md` §10). |
| 3.4 | Reverb (websockets) running | 📋 Realtime is the only push transport (no polling). `deploy/supervisor/giftlab-reverb.conf` + Nginx `wss` proxy. |
| 3.5 | Multi-worker web server (PHP-FPM), not `artisan serve` | ✅ Runbook uses PHP-FPM. (The single-threaded dev server caused the proof-composite self-call hang seen in testing — a dev artifact, not prod.) |
| 3.6 | TLS on all three hosts | 📋 Certbot (`DEPLOYMENT.md` §8). `SESSION_SECURE_COOKIE=true` requires HTTPS. |
| 3.7 | Nightly DB backup (`mysqldump` → Spaces) | 📋 Audit logs are dispute evidence — retain (`DEPLOYMENT.md` §13). |

## 4. Data & seeding

| # | Item | Status |
|---|------|--------|
| 4.1 | Migrations apply cleanly | ✅ Verified locally incl. the new go-live-adjacent migrations (amount_paid, RETURNED job state, processed_webhook_events). Run `migrate --force` on deploy. |
| 4.2 | **Change seeded staff passwords** | ⚠️ `superadmin@giftlab.local` / `ops@giftlab.local` seed with `ChangeMe!123` — change immediately after first login. |
| 4.3 | Pricing config + courier pickup address set | 📋 `migrate --seed` seeds pricing defaults; set the **real warehouse pickup address** in-app under Staff → Courier (it is not an env var). |
| 4.4 | Catalogue is orderable | ✅ Un-orderable products are kept off the storefront (LT1 fix, `Product::scopeBuyable`) — no variant-less products reach buyers. |

## 5. Security

| # | Item | Status |
|---|------|--------|
| 5.1 | Webhooks fail closed on bad/missing signature | ✅ Both Stripe and NinjaVan reject unsigned/mis-signed requests (401/400, no state change), with event-level idempotency/replay guard (`processed_webhook_events`). |
| 5.2 | Privilege escalation closed | ✅ Delegated user-manager can't reset a superadmin's password (H1); publish/import/auto-publish gated (H2, L25–L27); FE no longer grandfathers sensitive perms (L28). |
| 5.3 | Signed track links are PII-free | ✅ Name+qty only, no PII; permanent bookmarkable link by design (L17). |
| 5.4 | `LOG_LEVEL=warning`, no secrets in logs | ✅ Prod template sets `warning`. |

## 6. Post-deploy smoke test (`DEPLOYMENT.md` §12)

- 📋 `php artisan about` — confirm env=production, redis/reverb/s3 drivers, debug off.
- 📋 `curl https://api.<host>/api/catalogue` returns products.
- 📋 CSRF round-trip: `curl -si https://api.<host>/sanctum/csrf-cookie`.
- 📋 Reverb handshake: `curl -si https://reverb.<host>` (expect 101).
- 📋 Supervisor: worker + reverb `RUNNING`.
- 📋 In the SPA: staff login → production queue → confirm a state change pushes live to a second session (no refresh).
- 📋 Fire a NinjaVan test webhook → order status updates on the tracker.

---

## Launch-blockers summary (the ⚠️ items)

1. **Rotate the live `.env` secrets** (Spaces + Gmail + NinjaVan) → secret store. *(2.1)*
2. Set **`NINJAVAN_BASE_URL`** to the production host and **`NINJAVAN_WEBHOOK_SECRET`**. *(2.5, 2.6)*
3. Set **`ARTWORK_DISK=spaces_private`**. *(2.8)*
4. Get **`SESSION_DOMAIN` / `SANCTUM_STATEFUL_DOMAINS` / `CORS_ALLOWED_ORIGINS`** exactly matching the real hosts. *(2.9)*
5. **Change the seeded staff passwords.** *(4.2)*
6. If enabling B2C pay-now: set **`STRIPE_WEBHOOK_SECRET`**. *(2.7)*

Everything else is either ✅ verified in-repo or a 📋 standard step in the deployment runbook.
