# Observability & Alerting (Point 3) — Design Spec

**Date:** 2026-08-04
**Status:** Approved (brainstorming), pending spec review
**Decisions:** In-house alerting only (no third-party SaaS). Alerts by email to an
ops address. Health endpoint public. Reverb check omitted in v1.

---

## Problem

Three observability gaps were flagged. Investigation against current code shows
**one is already fully built** — narrowing the actual work to two:

- **(a) Unhandled exceptions are silent.** `bootstrap/app.php` maps four domain
  exceptions to friendly 4xx and has an inert Sentry `report()` seam (guarded by
  `app()->bound('sentry')`, no Sentry installed), plus a `dontReport` list for
  the domain guards. A genuine unhandled 500 is written to the log and **nobody
  is notified** — the "2am silent 500" case.
- **(c) No deep health check.** Only the framework's `/up` (fires
  `DiagnosingHealth`) exists. Nothing verifies DB / Redis / queue reachability
  for an uptime monitor.

**Already built (NOT in scope — do not rebuild):**
- **(b) Failed-job alerting.** `Queue::failing` is wired to
  `StaffNotifier::jobFailed` (`app/Providers/AppServiceProvider.php:268`), which
  logs, broadcasts `JobFailedAlert` to the staff console, and emails every
  staff-admin/superadmin via `JobFailedAlertMail` — with a static reentrancy
  guard and a must-never-throw body. `EnrichImportedModel3dProduct::failed()`
  logs only (deliberately, to avoid double-alerting). `queue:prune-failed` is
  scheduled (`routes/console.php:83`). Covered by `FailedJobAlertTest`. This
  design reuses its patterns but changes none of it.

---

## Goals

1. Email an ops address when an unexpected (reportable) exception occurs, without
   flooding on a storm.
2. A public health endpoint an uptime monitor can poll for DB/Redis/queue health.
3. No new external services; one new config file; one new optional env var.

## Non-goals

- Sentry / any SaaS or self-hosted error tracker.
- Reverb reachability probe (deferred — a flaky false-negative is worse than no
  check).
- Structured/JSON logging, correlation IDs, log aggregation (separate roadmap
  items).
- Touching the already-built failed-job pipeline.

---

## Design

### 1. Config — `config/alerts.php`

```
ops_email                  => env('OPS_ALERT_EMAIL')            // nullable
exception_throttle_minutes => (int) env('OPS_ALERT_THROTTLE', 15)
```

`ops_email` null ⇒ fall back to the staff-admin/superadmin recipient list
(exactly how `StaffNotifier::jobFailed` already resolves recipients).

### 2. (a) Unexpected-exception alert

**New method `StaffNotifier::unexpectedException(Throwable $e)`** — a sibling to
the existing `jobFailed`, following the same rules:

- **Must never throw.** It runs inside the framework's report pipeline; an
  exception escaping here would compound the original fault. Whole body wrapped;
  every step defensive (mirrors `jobFailed`).
- **Throttled by signature.** Signature = a hash of exception class + message (or
  class + file:line). Uses the cache (`Cache::add(key, true, ttl)` as an atomic
  "first one through the window wins") — if the key already exists, log-and-skip
  the email. TTL = `config('alerts.exception_throttle_minutes')`. Prevents a
  500-storm from flooding the ops inbox. (This is the one behavioural addition
  over `jobFailed`, which relies only on its reentrancy guard.)
- **Recipients:** `config('alerts.ops_email')` if set, else the
  staff-admin/superadmin emails (reuse the `jobFailed` resolution).
- **Sends `ExceptionAlertMail`** (new mailable, `ShouldQueue`, modelled on
  `JobFailedAlertMail`) carrying exception class, message, and request path if
  available. No stack trace in the email body beyond the message + location —
  keep it lean and non-leaky.

**Wiring:** in `bootstrap/app.php` `->withExceptions`, add to the existing
`report()` callback (the one guarding the Sentry seam) a call to
`app(StaffNotifier::class)->unexpectedException($e)`. Because the domain
exceptions are already in `dontReport`, and the framework never reports its
internal 4xx/validation/auth exceptions, only genuinely reportable (≈5xx /
uncaught) exceptions reach this callback — no 4xx/validation noise. Keep the
Sentry seam intact (it stays a no-op until a Sentry binding ever exists).

### 3. (c) Public health endpoint

**`GET /api/health`** → new `HealthController` (route in `routes/api.php`,
outside any auth middleware — public by decision).

- Runs three checks, each wrapped so one failure doesn't abort the others:
  - **database** — `DB::connection()->getPdo()` / a `select 1`.
  - **redis** — `Redis::connection()->ping()`.
  - **queue** — the configured queue connection is resolvable / its backing store
    reachable (for the redis queue driver this overlaps the redis check; assert
    the connection resolves without throwing).
- **Response body — booleans only, no infra detail** (no versions, hostnames,
  driver names, or exception messages), to limit what a public endpoint leaks:
  ```json
  { "ok": true, "checks": { "database": true, "redis": true, "queue": true } }
  ```
- **Status:** `200` when every check passes, `503` when any fails.
- **Short-cache ~5s.** Cache the computed result for a few seconds
  (`Cache::remember('health:probe', 5s, ...)`) so an unauthenticated request
  flood can't hammer DB/Redis on every hit (public-endpoint DoS-amplification
  mitigation). A 5s staleness is fine for uptime polling.
- Leave the framework's `/up` untouched.

---

## Data flow

```
Unhandled 500  → framework report() → (Sentry seam no-op)
                                     → StaffNotifier::unexpectedException()
                                     → throttle check (cache) → ExceptionAlertMail → ops

Uptime monitor → GET /api/health → HealthController → cache(5s)[db,redis,queue] → 200/503 booleans
```

## Error handling

- `unexpectedException` never throws; a throttled or recipient-less case logs and
  returns. A mail-queue failure is caught (mirrors `jobFailed`'s per-recipient
  try/catch).
- Each health check is individually guarded; a thrown check ⇒ that check reads
  `false`, overall `ok=false`, `503`. The endpoint itself never 500s.

## Testing (TDD)

- **`unexpectedException`:** emails the ops address once for a reportable
  exception; a second call with the same signature inside the window is
  suppressed (no second mail); a different signature is not; never throws when
  the mailer throws (assert it swallows).
- **Recipient resolution:** with `alerts.ops_email` set → that address; unset →
  staff-admin/superadmin list.
- **report() wiring:** a reportable exception routed through the handler triggers
  one alert; a `ValidationException` and a `DomainRuleException` trigger none.
- **Health:** `200` + all-true booleans when healthy (test env); `503` when a
  check is forced to throw (bind a failing check or mock the facade); body
  contains no version/host/message strings.
- Both suites green at branch point; keep them so.

---

## Files touched

**New**
- `config/alerts.php`
- `app/Mail/ExceptionAlertMail.php` (+ `resources/views/mail/exception-alert.blade.php`)
- `app/Http/Controllers/HealthController.php`
- `tests/Feature/ObservabilityAlertingTest.php`

**Modified**
- `app/Services/StaffNotifier.php` (add `unexpectedException` + throttle helper)
- `bootstrap/app.php` (call `unexpectedException` from the existing `report()`)
- `routes/api.php` (public `/health` route)
- `deploy/.env.production.example` (document `OPS_ALERT_EMAIL`, `OPS_ALERT_THROTTLE`)

## Open items for the owner

- Set `OPS_ALERT_EMAIL` in production (else alerts go to the staff-admin list).
- Point the uptime monitor at `GET /api/health` (expects `200`/`503`).
