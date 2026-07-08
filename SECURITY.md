# Security & QC Audit (Phase 5)

Audit of Phases 1–4 against the OWASP Top 10. Status is one of **PASS** (verified
in code), **PATCHED** (gap found and fixed this phase), or **CONFIG** (must be set
during Phase 6 deployment hardening - enforced by infrastructure/config, not app
code).

## OWASP Top 10

| # | Category | Status | Notes |
|---|----------|--------|-------|
| A01 | Broken Access Control | PASS / PATCHED | Tenancy enforced in `QuotePolicy`, controller guards (`authorizeQuote`, `ensureStaff`), and scoped list queries. Broadcast channels (`routes/channels.php`) mirror the same rules - realtime access can't exceed HTTP access. Buyer→other-company quote returns 403 (test: `TenancyTest`). |
| A02 | Cryptographic Failures | PASS / CONFIG | Passwords `bcrypt` (hashed cast). Secrets in `.env` (git-ignored). CONFIG: force HTTPS + `SESSION_SECURE_COOKIE=true` + HSTS in prod. |
| A03 | Injection | PASS / PATCHED | Eloquent parameter binding everywhere; no raw SQL. Catalogue search binds `LIKE` param. XSS: React auto-escapes; the one URL rendered as `href` (proof artwork) is now scheme-allowlisted via `safeHref()` (blocks `javascript:`/`data:`). |
| A04 | Insecure Design | PASS | Two production gates enforced as guarded state transitions, not UI. Margin floor enforced server-side. Procurement isolated per class so one track can't corrupt another. |
| A05 | Security Misconfiguration | PATCHED / CONFIG | Rate limits added to all routes (login 6/min, public 60/min, authed 120/min). CONFIG items below. |
| A06 | Vulnerable Components | CONFIG | Run `composer audit` + `npm audit` in CI (Phase 6). Pin versions; Dependabot. |
| A07 | Identification & Auth Failures | PATCHED | Added `AuthController`: `Auth::attempt` + `session()->regenerate()` (anti-fixation), uniform failure message (anti-enumeration), logout invalidates session + rotates CSRF token, login throttled. |
| A08 | Software & Data Integrity | PASS | Approved proof is immutable (model `updating` guard); every price amendment / proof approval / stock re-check is written to append-only `audit_logs`. |
| A09 | Logging & Monitoring | PASS / CONFIG | Domain audit trail in place. CONFIG: ship Laravel logs + failed-login events to a central sink; alert on 5xx + auth-throttle hits. |
| A10 | SSRF | N/A (v1) | No server-side fetch of user-supplied URLs in the spine. When the Phase-2 scraper/3D-API clients land, restrict outbound hosts to an allowlist. |

## Patches applied this phase

- `app/Http/Controllers/AuthController.php` + `app/Http/Requests/LoginRequest.php` - hardened SPA auth.
- `routes/api.php` - per-tier throttling; auth endpoints (`/login`, `/logout`, `/user`).
- `frontend/src/lib/safeHref.ts` + `QuoteDetailPage` - href scheme allowlist.

## Required Phase-6 deployment config (CONFIG items)

These are set in the Laravel skeleton / server, not in the code already written:

1. **Sanctum stateful domains** - `SANCTUM_STATEFUL_DOMAINS` = the SPA origin(s); ensure `EnsureFrontendRequestsAreStateful` is in the `api` middleware group so cookie auth + CSRF apply.
2. **CORS** (`config/cors.php`) - `paths` include `api/*`, `sanctum/csrf-cookie`, `broadcasting/auth`; `allowed_origins` = SPA origin (no `*`); `supports_credentials = true`.
3. **Session/cookies** - `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax` (or `none` + Secure if cross-site), `SESSION_DOMAIN` set to the shared parent domain.
4. **Broadcasting auth** - the `/broadcasting/auth` route must run through `auth:sanctum`; only the channel closures in `routes/channels.php` authorize private subscriptions.
5. **HTTPS everywhere** - Reverb over `wss://` (`VITE_REVERB_SCHEME=https`), Nginx TLS (Certbot), HSTS header.
6. **Security headers** (Nginx) - `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, a CSP tuned to the SPA + Reverb origins.
7. **Dependency scanning** - `composer audit` and `npm audit` gated in CI.

## Residual / deferred

- File-upload endpoint for designer artwork is not yet server-side (artwork ref is a string in v1). When added, validate MIME + size + re-encode images, and store outside the web root (S3/DO Spaces).
- Phase-2 scraper/3D-API clients: add outbound-host allowlist (SSRF) and honour each API's rate-limit/attribution terms.
