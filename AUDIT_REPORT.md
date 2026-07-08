# Comprehensive Codebase Quality & Security Audit Report

> Generated: 2026-07-01 · Scope: `app/` (Laravel 11 backend) + `frontend/src/` (React 18 + TypeScript + Zustand + Vite).
> Method: non-destructive static analysis, authz/state-machine trace, N+1 & re-render inspection, error-path tracing. No code was modified.
> Amended: 2026-07-01 (Pass 2) - new findings appended, marked _[Pass 2]_; matrix counts updated. No duplicates of Pass 1.
> Amended: 2026-07-01 (Pass 3) - findings marked _[Pass 3]_; matrix updated. No duplicates of Pass 1/2.
> Amended: 2026-07-01 (Pass 4) - findings marked _[Pass 4]_; matrix updated. No duplicates of Pass 1/2/3.
> Amended: 2026-07-01 (Pass 5) - findings marked _[Pass 5]_; matrix updated. No duplicates of Pass 1–4.
> Amended: 2026-07-01 (Pass 6) - findings marked _[Pass 6]_; matrix updated. No duplicates of Pass 1–5. Verified the webhook-race fix (`PaymentService::confirmPaid`) landed correctly (lockForUpdate + txn + unique-violation collapse + logging).

## 1. Executive Architecture Summary

* **Style:** Decoupled SPA. Laravel 11 exposes a JSON API under `/api` (routes/api.php) authenticated by **Sanctum stateful cookies** (`statefulApi()` middleware, CSRF via `/sanctum/csrf-cookie`). React talks to it through a single axios instance (`frontend/src/lib/api.ts`, `withCredentials + withXSRFToken`).
* **Real-time:** All post-quote updates are pushed over **Laravel Reverb** (Pusher protocol) private channels; the client explicitly never polls. Channel authorization (routes/channels.php) mirrors HTTP tenancy (`company.{id}` = own company or staff; `staff.*` = staff only).
* **Domain shape:** A quote "spine" state machine (DRAFT→SENT→ACCEPTED→PROOFING→PROOF_APPROVED→PO_ISSUED→CONFIRMED→PROCURING→READY) orchestrated by `QuoteService`, with guarded `transitionTo()` on models and immutable proof sign-off. Pricing (`PricingService`) and config (`PricingConfig`) are fully table-driven.
* **Overall health:** **Good and notably security-conscious.** `declare(strict_types=1);` is universal, FormRequests validate input, migrations index every foreign key and hot lookup, the exception layer converts domain faults to friendly 4xx (never 500), and stored-XSS is pre-empted by rejecting SVG uploads. The residual risk is concentrated in (a) a payment-webhook concurrency race, (b) an **inconsistent authorization pattern** with a dead `QuotePolicy`, (c) two backend N+1 loops, and (d) a **class of frontend mutation actions that swallow API errors**, producing silent failures / feedback-less UI on the write path.

---

## 2. Backend Folder (`app/`) Audit

### 🔴 Critical & Security Issues

* [✅] DONE - **File:** `app/Services/Payment/PaymentService.php` (`confirmPaid`)
  * **Issue:** TOCTOU race on payment capture. Idempotency was a read-then-write guard with no row lock; concurrent `checkout.session.completed` deliveries could both pass the guard → duplicate `po_ref` insert → uncaught `QueryException` → 500 (more Stripe retries) and double `procure()`.
  * **Fix:** `confirmPaid` now runs inside `DB::transaction` and takes `Quote::lockForUpdate()` on the quote row, serializing concurrent deliveries - the second blocks until the first commits, then observes the existing PO and returns idempotently. Any residual unique-violation (SQLSTATE 23000/23505) is caught and collapsed into success; other `QueryException`s are logged with context and rethrown. No schema change (avoids breaking the B2B multi-PO path).

* [✅] DONE - **File:** `app/Providers/AppServiceProvider.php` (`PaymentGateway` binding) _[Pass 3]_
  * **Issue:** **Fail-open payment gateway.** Container bound `FixturePaymentGateway` whenever `config('services.stripe.secret')` was falsy; fixture `confirmsImmediately()` returns `true` → PO marked **PAID with no real charge**. `.env.example` ships empty `STRIPE_SECRET`, so a prod deploy could silently revert to the auto-succeed fixture → free orders into production.
  * **Fix:** Binding now **fails closed**. Stripe secret present → `StripePaymentGateway`. Secret absent AND env is not `local`/`testing` → throw `RuntimeException` naming the environment and the risk, refusing to resolve the gateway (pay-now path errors instead of granting free orders). Fixture only reachable in `local`/`testing`.

* [✅] DONE - **File:** `app/Policies/QuotePolicy.php` + `app/Providers/AppServiceProvider.php` + `app/Http/Controllers/Controller.php` + `app/Http/Controllers/QuoteController.php`
  * **Issue:** `QuotePolicy` (`view/update/amend/manageProduction`) defined but **never registered nor invoked** - no central authz safety net; a new endpoint forgetting its inline `abort_unless()` inherited no protection.
  * **Fix:** Base `Controller` now `use AuthorizesRequests`. Policy registered explicitly in `AppServiceProvider::boot` via `Gate::policy(Quote::class, QuotePolicy::class)`. `QuoteController` inline `abort_unless()`/`ensureStaff()` replaced with `$this->authorize()`: `show`→`view`, `accept`→`update`, `send`/`procure`→`manageProduction`. Dead helpers removed. `AuthorizationException` → 403 via default handler. Policy now the central enforced net.

### 🟡 Major & Performance Issues

* [✅] DONE - **File:** `app/Http/Controllers/PriceEstimateController.php` (`__invoke`)
  * **Issue:** N+1. `Product::find`/`Variant::find` called per line inside the loop; up to 100 line items → ~200 queries per estimate on a public, unauthenticated, per-cart-change endpoint (light-DoS vector).
  * **Fix:** Product/variant IDs collected + deduped up front, then **two batched `whereIn(...)->get()->keyBy('id')`** loads (products, variants) resolved from memory in the loop. Query count now constant (2) regardless of cart size; identical publish-state gate and 422 behavior preserved.

* [✅] DONE - **File:** `app/Http/Requests/StoreQuoteRequest.php` (`withValidator`)
  * **Issue:** N+1. `Product::find` per line in the validation loop to check `publish_state` - one query per cart line, compounding under bulk carts.
  * **Fix:** IDs collected + deduped, single `whereIn(...)->get()->keyBy('id')` load, publish-state checked from memory. One query regardless of cart size; enum-cast `publish_state` comparison preserved (models kept, not raw `pluck`, to keep the `PublishState` cast intact).

* [✅] DONE - **File:** `app/Services/QueueService.php` (`queue`)
  * **Issue:** `->with('quote')` eager-loaded the quote relation, but `ProductionJobResource` emits only `quote_id` (FK on the job row) and never reads the relation - dead extra `whereIn` query + hydration per queue read.
  * **Fix:** Dropped the `with('quote')` eager-load; `queueOrder()->get()` only. One fewer query + no wasted hydration on every production-queue read.

* [✅] DONE - **File:** `app/Http/Controllers/AdminCatalogueController.php` (`index`)
  * **Issue:** Unbounded `->get()->map(...)` over all SCRAPED_UV + MODEL_3D products; response/memory grew linearly with catalogue size.
  * **Fix:** `->paginate($perPage)` (default 24, clamped 1–100 via `per_page`), collection transformed in place, response now returns `data` + `meta{current_page,last_page,per_page,total}` - matches the public paginated shape.

* [✅] DONE - **File:** `app/Models/PricingConfig.php` (`value`)
  * **Issue:** Request-scoped `private static` memo flushed only in the same process; a long-lived worker (Octane/queue) never observed a config row updated by another process.
  * **Fix:** Two-layer read - per-request static memo on top of a shared `Cache::remember` (30s TTL) that bounds cross-process staleness; a write in this process still `Cache::forget`s the exact `pricing_config:{group}:{key}` immediately (in `saved`/`deleted` hooks). php-fpm behavior unchanged; persistent runtimes now converge within TTL.

* [✅] DONE - **File:** `app/Events/QuoteStateChanged.php` (+ `ProofStatusChanged`, `ProductionQueueUpdated`, `LineItemAwaitingReconfirm`) + dispatch sites in `QuoteService`, `QueueService`, `ProcurementManager` _[Pass 2]_
  * **Issue:** Events were `ShouldBroadcastNow` (synchronous) and dispatched inside `DB::transaction` closures → (a) phantom broadcast on rollback; (b) slow/unreachable Reverb blocked the request holding the txn open and could roll back a successful action.
  * **Fix:** All four events now `implements ShouldBroadcast` (queued, off the request thread), and every in-transaction dispatch is wrapped in `DB::afterCommit(fn () => …::dispatch(...))` - broadcasts fire only after the txn commits (and never on rollback). Reverb availability/latency is now decoupled from write-path correctness.

* [✅] DONE - **File:** `app/Http/Controllers/AdminCatalogueController.php` (`publish`) + `app/Services/Catalogue/ScrapedCatalogueService.php` (`publish`) _[Pass 2]_
  * **Issue:** Controller set `Published` directly on a `ReadyToApprove` flag, bypassing `CompletenessGate` (unlike the service path). A drifted item could be pushed public without re-validation.
  * **Fix:** Controller now delegates to `ScrapedCatalogueService::publish()`, which re-gates on `CompletenessGate::isComplete`; on failure the service records `cannot_publish_reasons` (→ `CannotPublish`) and the controller returns **422 with the reasons** instead of publishing. Single gated publish path.

* [✅] DONE - **File:** `app/Services/Procurement/ProcurementManager.php` (`procureLine`) _[Pass 2]_
  * **Issue:** Chained line-item transitions (`onProcured` 4×, `onReconfirm` 1×) plus the strategy stock decrement were **not wrapped in a transaction**; a failure mid-chain stranded a line in `PROCURING`/`PURCHASED`/`INBOUND`, blocking `tryQueue` forever.
  * **Fix:** `procureLine` now runs the transition chain + strategy inside a **per-line `DB::transaction`** - the whole line resolves (Ready) or rolls back atomically; other lines in the `procure` loop are unaffected. Reconfirm broadcast moved to `DB::afterCommit`. (Per-line granularity chosen so one line's failure doesn't undo already-procured siblings.)

* [✅] DONE - **File:** `LineItem.php`, `Proof.php`, `ProductionJob.php`, `PurchaseOrder.php`, `Variant.php` + `Quote.php`/`Product.php` cascade hooks + migration `2026_07_01_000017_add_soft_deletes_to_quote_children.php` _[Pass 3]_
  * **Issue:** Soft-delete cascade gap - children lacked `SoftDeletes`; a soft-deleted parent left live orphan children, and a cancelled quote's job lingered on the shared floor queue.
  * **Fix:** All five children now `use SoftDeletes` (migration adds `deleted_at`). `Quote::booted` cascades soft-delete (and restore) to line items/proofs/jobs/POs; `Product::booted` cascades to variants - both skip on `isForceDeleting()` (DB-level FK cascade handles hard deletes). `ProductionJob::scopeQueueOrder` now `->whereHas('quote')` so jobs of soft-deleted/cancelled quotes drop off the queue.

* [✅] DONE - **File:** `app/Http/Resources/ProductResource.php` + `frontend/src/types.ts` + `frontend/src/pages/CataloguePage.tsx` (+ tests) _[Pass 4]_
  * **Issue:** Internal cost disclosure - `ProductResource` emitted raw pre-margin `base_cost` on the public `/catalogue`; competitors could back out the margin.
  * **Fix:** Resource now emits **`from_price`** (indicative sell price = `PricingService::unitPrice($product, null, 1)`), not `base_cost`. Public `Product` type + `CataloguePage` render `from_price.toFixed(2)`; fixtures updated. Admin endpoint (separate array) still shows `base_cost` to staff.

* [✅] DONE - **File:** `app/Exceptions/DomainRuleException.php` (new) + `bootstrap/app.php` + guards in `QuoteService`, `PaymentService`, `ProcurementManager`, `Proof` _[Pass 4]_
  * **Issue:** Plain `RuntimeException`/`InvalidArgumentException`/`LogicException` from service/model guards fell through to a raw HTTP 500 (with stack trace under `APP_DEBUG`), instead of the friendly 422 the domain-exception path gives.
  * **Fix:** New `DomainRuleException extends RuntimeException`; all guard throws ("Only DRAFT quotes can be amended", proof-immutability, non-procurable line, "Quote must be PROOF_APPROVED…", etc.) converted to it. `bootstrap/app.php` maps it to **422 with the guard's own safe message**, logged at warning. No unrelated base classes swallowed.

* [✅] DONE - **File:** `app/Services/Model3d/HttpThingiverseClient.php` (`fetch`) _[Pass 5]_
  * **Issue:** External call had no `timeout`/`connectTimeout` (Laravel HTTP client has no default) → hangs on a stalled upstream; non-2xx swallowed as silent null with no log.
  * **Fix:** Added `->connectTimeout(5)->timeout(15)->retry(2, 500, throw: false)`; wrapped in try/catch logging transport errors, and non-success statuses now `Log::warning` with status + source id. No more indefinite hang, no more invisible upstream failures.

* [✅] DONE - **File:** `routes/console.php` _[Pass 5]_
  * **Issue:** Daily `catalogue:resync-scraped` had no `onOneServer`/`withoutOverlapping`; every node in the multi-node deploy fired it at 03:00 → duplicate racing re-syncs.
  * **Fix:** Added `->onOneServer()->withoutOverlapping()` - only one node runs it per tick and a long run never overlaps the next. (Requires a shared non-array cache driver in prod, noted inline.)

### 🟢 Minor & Code Hygiene Issues

* [✅] DONE - **File:** `QuoteController.php` / `PayNowController.php` / `ProductionQueueController.php`
  * **Issue:** Inconsistent authz surface - mix of inline `abort_unless()` and FormRequest `authorize()`, hard to audit alongside the (formerly) dead policy.
  * **Fix:** Quote/pay/queue inline checks now route through `QuotePolicy` via `$this->authorize()` (`view`/`update`/`manageProduction`) - a single convention backed by the registered policy. FormRequest `authorize()` (amend/advance/reconfirm/proof-decide) remains for request-shaped guards; both now consult the same tenancy rules.
* [✅] DONE - **File:** `app/Http/Controllers/StripeWebhookController.php`
  * **Issue:** Malformed/unknown-quote event silently no-op'd (`Quote::find(0)`), so a metadata regression was invisible.
  * **Fix:** `else` branch now `Log::warning`s with `quote_id` + `session_id` when a verified event's quote can't be resolved.
* [✅] DONE - **File:** `app/Services/AuditLogger.php` (`log`) _[Pass 2]_
  * **Issue:** Console/queue mutations wrote audit rows with `user_id = null` and `ip_address = null`, losing source attribution.
  * **Fix:** When `app()->runningInConsole()`, `ip_address` is set to the `'console'` sentinel instead of null - background drift-pulls are now attributable to the system source rather than reading as missing data.
* [✅] DONE - **File:** `app/Services/Catalogue/ScrapedCatalogueService.php` (`ingest`) _[Pass 2]_
  * **Issue:** `withTrashed()->first() ?? new Product` silently resurrected a soft-deleted (intentionally removed) listing on re-ingest.
  * **Fix:** A trashed match is now **skipped and `Log::info`'d** (returned untouched); only live rows are updated, a new source id still creates a fresh row. Intentional removals stay removed.
* [✅] DONE - **File:** `config/session.php` + `.env.example` _[Pass 3]_
  * **Issue:** `secure` had no safe default (`env('SESSION_SECURE_COOKIE')` → null when unset) → session cookie could travel over plaintext HTTP if prod forgot the flag.
  * **Fix:** `'secure' => env('SESSION_SECURE_COOKIE', env('APP_ENV') !== 'local')` - fails safe (secure) for any non-local env. `.env.example` documents the prod requirement (`=true` + shared `SESSION_DOMAIN`).
* [✅] DONE - **File:** `config/cors.php` _[Pass 3]_
  * **Issue:** `allowed_methods => ['*']` with `supports_credentials => true` - broader than needed.
  * **Fix:** Enumerated `['GET','POST','PATCH','PUT','DELETE','OPTIONS']` (SPA verbs + preflight).
* [✅] DONE - **File:** `app/Enums/QuoteState.php` + `QuoteService::cancel` + `QuoteController::cancel` + `routes/api.php` _[Pass 4]_
  * **Issue:** `CANCELLED` was unreachable - no caller/route; early states (Draft…ProofApproved) had no cancel edge, so a quote couldn't be abandoned.
  * **Fix:** Added `Cancelled` as a legal next state from every pre-production stage (Draft, Sent, ChangesRequested, Accepted, Proofing, ProofApproved, PoIssued; Confirmed/Procuring already had it; READY/CLOSED intentionally cannot cancel). New `QuoteService::cancel()` (txn + audit + afterCommit broadcast), `QuoteController::cancel` (policy `update`), and `POST /quotes/{quote}/cancel` route. `CANCELLED` is now wired and reachable.
* [✅] DONE - **File:** `app/Models/Variant.php` (`hasStockFor`) _[Pass 5]_
  * **Issue:** `hasStockFor(int $qty)` dead code - zero callers; `CoreProcurement::procure` inlines the equivalent check.
  * **Fix:** Removed the abandoned helper.
* **Positive:** `declare(strict_types=1);` present in **every** PHP file; all `try/catch` blocks log with context (`Log::error`/`warning`, or `report()` in `ResyncScrapedCatalogue`) - no silent swallowing found in backend. Migrations index every FK plus composite hot paths (`[company_id,state]`, `[quote_id,line_state]`, `[state,ready_at]`). `CoreProcurement` correctly `lockForUpdate()`s variant stock against oversell.

---

## 3. Frontend Folder (`frontend/src/`) Audit

### 🔴 Critical & UX Resilience Issues

* [✅] DONE - **File:** `frontend/src/stores/quoteStore.ts` (`send`/`accept`/`procure`/`issueProof`/`decideProof`/`issuePurchaseOrder`)
  * **Issue:** Six mutation actions had no try/catch and never set `error` → rejections became unhandled promise rejections and the UI showed no feedback.
  * **Fix:** Each now `set({ error: null })`, wraps the request + refetch in try/catch, and `set({ error: apiError(err) })` on failure (matching `payNow`). No unhandled rejection; `QuoteDetailPage` renders the store error via `AsyncBoundary`. Refetch-on-success keeps state truthful even if the broadcast is missed.

* [✅] DONE - **File:** `frontend/src/stores/queueStore.ts` (`advance`) + `frontend/src/pages/ProductionQueuePage.tsx`
  * **Issue:** `advance()` had no error handling and relied on the broadcast as the sole update; a rejected transition or dropped socket left the queue frozen with no feedback, and the button had no double-submit guard.
  * **Fix:** `advance` now try/catches (sets `error`) and does a single post-mutation `fetchQueue()` reconcile (not a poll) on both success and failure. `ProductionQueuePage` adds a `pendingId` single-flight guard disabling the button in flight.

* [✅] DONE - **File:** `frontend/src/lib/echo.ts` (`getEcho`) + `queueStore`/`quoteStore` subscriptions
  * **Issue:** No connection-state/reconnect handling and no refetch fallback; events missed during a socket drop were never reconciled → views silently diverged from server truth.
  * **Fix:** `getEcho` binds the Pusher connection `state_change` and, on a RE-connect (not first connect), fans out to registered handlers via new `onEchoReconnect(cb)`. `queueStore.subscribe` and `quoteStore.subscribeCompany` register a refetch (queue / quotes list + open quote) and unregister on teardown; `disconnectEcho` clears handlers. Missed events now reconcile automatically on reconnect.

### 🟡 Major & Performance Issues

* [✅] DONE - **File:** `frontend/src/stores/catalogueAdminStore.ts` (`publish`/`unpublish`/`setAutoPublish`) + `frontend/src/stores/procurementStore.ts` (`reconfirm`)
  * **Issue:** Swallowed rejections, no `error` state; `reconfirm` dropped the alert only after success but left rejections unhandled.
  * **Fix:** All actions now try/catch and set `error` (added `error` to procurementStore). `reconfirm` only drops the alert on success, keeping it visible on failure. `setAutoPublish` returns success + only flips stored `autoPublish` after the PATCH persists.

* [✅] DONE - **File:** `frontend/src/pages/ProductDesignerPage.tsx` (`addToCart`)
  * **Issue:** Upload failure was swallowed (`artwork_ref = null`) then the line was added + cart navigated anyway → silent artwork loss.
  * **Fix:** On upload failure it now sets `uploadError` (rendered with `role="alert"`) and **aborts** - no line added, no navigation. Buyer sees the failure and can retry instead of losing the design.

* [✅] DONE - **File:** `frontend/src/pages/QuoteDetailPage.tsx` + `frontend/src/lib/roles.ts` (new)
  * **Issue:** `isStaff = user?.role !== 'buyer'` was fail-open - unknown/future role or null rendered staff controls.
  * **Fix:** New shared `isStaffRole()` positive allowlist (`staff_admin`/`superadmin`, deny by default), used here and in `Layout`. Matches `ProtectedRoute` + backend `User::isStaff()`.

* [✅] DONE - **File:** `frontend/src/lib/api.ts`
  * **Issue:** No global 401 handling - mid-session expiry scattered generic errors with no re-auth redirect.
  * **Fix:** Response interceptor redirects to `/login` on 401 and resets the CSRF flag, **excluding** the `/user` anonymous probe and `/login` (handled inline) and skipping if already on `/login` - so public browsing isn't hijacked.

* [✅] DONE - **File:** `frontend/src/pages/CatalogueAdminPage.tsx` + `catalogueAdminStore` + `AdminCatalogueController::index` _[Pass 2]_
  * **Issue:** Auto-publish checkbox was a local `useState(false)` never hydrated from the server, and flipped before awaiting a swallow-error PATCH → could show a policy change that never persisted.
  * **Fix:** `index` now returns `auto_publish` in `meta`; store hydrates `autoPublish` from it on `fetch` and only flips it after the PATCH persists (error on failure). Page reads the store value (local state removed); checkbox always reflects the real server setting.

* [✅] DONE - **File:** `frontend/src/App.tsx` (company-channel effect) _[Pass 2]_
  * **Issue:** Effect depended on the whole `user` object (new ref every `fetchUser`/`login`) → tore down + re-established the private-channel subscription even when `company_id` was unchanged.
  * **Fix:** Extracted `const companyId = user?.company_id ?? null` and depend on that primitive - the subscription is now stable across user-object identity changes; no needless churn/event-miss window.

* [✅] DONE - **File:** `QuoteListPage.tsx` + `CataloguePage.tsx` + `quoteStore.ts` (`fetchQuotes`) _[Pass 6]_
  * **Issue:** Pagination ignored - frontend read only `data.data`, rendered no pager; page 2+ unreachable. `Paginated<T>` was dead.
  * **Fix:** `fetchQuotes(page)` + store `page`/`lastPage` from `meta`; `CataloguePage` gained `page`/`lastPage` state + `load(page)`. Both render a Prev/Next pager (shown when `lastPage > 1`) typed via the now-used `Paginated<T>`. Reconnect refetch preserves the current page.

### 🟢 Minor & Code Hygiene Issues

* [✅] DONE - **File:** `frontend/src/stores/cartStore.ts` (`updateQty`)
  * **Issue:** `Math.max(1, qty)` didn't neutralize `NaN` (emptied number input) → `NaN` qty leaked into state/estimate.
  * **Fix:** `Number.isFinite(qty) ? Math.max(1, Math.floor(qty)) : 1` - always a valid integer ≥ 1.
* [✅] DONE - **File:** `frontend/src/components/DesignerCanvas.tsx`
  * **Issue:** File input `accept` allowed SVG while the backend deliberately rejects it - misleading/inconsistent.
  * **Fix:** `accept="image/png,image/jpeg"` - matches the backend `ArtworkUploadRequest` posture.
* [✅] DONE - **File:** `frontend/src/pages/ProductDesignerPage.tsx`
  * **Issue:** `eslint-disable react-hooks/exhaustive-deps` hid a stale-closure footgun on the load effect.
  * **Fix:** `load` wrapped in `useCallback([id])`, effect deps `[load]`; lint suppression removed.
* [✅] DONE - **File:** `frontend/src/pages/ProcurementPage.tsx` + `frontend/src/pages/CatalogueAdminPage.tsx` _[Pass 2]_
  * **Issue:** No double-submit lockout on Amend/Accept/Drop/Publish/Unpublish buttons.
  * **Fix:** Both pages add a `pendingId` single-flight guard (via a `run`/`runRow` wrapper) that disables the row's mutation buttons while a request is in flight. ProcurementPage also renders the store `error`.
* [✅] DONE - **File:** `frontend/src/pages/CataloguePage.tsx` _[Pass 2]_
  * **Issue:** `<img src={p.image_url}>` rendered the scraped external URL directly - no `safeHref`, no `onError` fallback.
  * **Fix:** New `CardImage` component routes the URL through `safeHref`, falls back to the initial-letter placeholder on load error, and sets `referrerPolicy="no-referrer"` + `loading="lazy"`.
* [✅] DONE - **File:** `frontend/src/components/Layout.tsx` _[Pass 3]_
  * **Issue:** Staff nav gated by fail-open `user?.role !== 'buyer'` (duplicated anti-pattern).
  * **Fix:** Now uses the shared `isStaffRole(user?.role)` positive allowlist (same helper as `QuoteDetailPage`).
* [✅] DONE - **File:** `frontend/src/types.ts` (`Paginated<T>`) _[Pass 6]_
  * **Issue:** `Paginated<T>` was dead code (never imported).
  * **Fix:** Now used to type the paginated `/quotes` and `/catalogue` responses (see the pagination fix) - no longer dead.
* **Positive:** `AsyncBoundary` (`components/ui/States.tsx`) enforces loading/error/empty on read paths consistently; `disconnectEcho()` is correctly called on logout; store channel subscriptions are guarded against double-subscribe and torn down in effect cleanup (`ProductionQueuePage` L18); `safeHref` correctly whitelists http(s)/relative for artwork links; `LoginPage` disables submit while in-flight and renders `error`.

---

## 4. Final Risk Assessment Matrix

| Category | Total Issues Found | Highest Risk Area | Fix Priority (High/Med/Low) |
| :--- | :--- | :--- | :--- |
| Security & Auth | 8 | **Fail-open payment gateway** (no `STRIPE_SECRET` → auto-capture free orders); webhook capture race; public `base_cost` cost-disclosure; dead `QuotePolicy`; publish bypasses `CompletenessGate` | **High** |
| Performance & DB | 8 | Public `PriceEstimateController` N+1 (~200 q/estimate); soft-delete cascade gap; multi-node scheduler duplicate runs | **Med** |
| UI/UX Resilience | 13 | Write-path swallows API errors; `ShouldBroadcastNow` inside DB txns; non-atomic procurement; unmapped service 500s; no-timeout external HTTP hang; pagination unreachable past page 1 | **High** |
| Code Hygiene | 11 | `isStaff`/nav fail-open (2 sites); dead `CANCELLED` state + dead `hasStockFor` + dead `Paginated<T>`; session cookie Secure default; audit actor loss | **Low** |

_Pass 1: 18 · Pass 2: +9 · Pass 3: +5 · Pass 4: +3 · Pass 5: +3 · Pass 6: +2. **Total: 40 issues across six passes.** ✅ **All 40 remediated** - backend `php artisan test` 68 passed, frontend `tsc` + `vitest` (15) + `vite build` all green._

---

_Backend is hardened (strict types everywhere, indexed FKs, friendly exception layer, SVG XSS defense). The dominant real-world risk is on the **frontend write path**: a whole class of mutation actions reject silently, and the UI trusts Reverb as the only reconciliation channel - so failures and dropped sockets manifest as a UI that looks fine but is wrong. Address those and the webhook race first._
