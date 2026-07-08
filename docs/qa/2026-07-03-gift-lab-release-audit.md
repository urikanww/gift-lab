# gift-lab - Release-Readiness QA Audit

**Date:** 2026-07-03
**Branch:** `qa/release-audit`
**Mode:** read-only evaluation - no application code changed
**Method:** 5 parallel pillar-lane agents (functional-e2e, security, performance-stability, ux-usability, cross-device-a11y). Live dev-server observation + static code analysis. Verification via `preview_eval` geometry / `getComputedStyle` / DOM snapshot, API responses, and the full test suites - no screenshots / rAF assertions (headless constraints).

---

## Verdict: 🔴 NO-GO

Gate rule: *any open P0 = NO-GO; all P1s must be triaged before release.*

**1 P0** (buyer order tracker permanently broken) and **8 P1s** are open. The app's automated gates are green (Pest 156 pass / Vitest 58 pass / `tsc` clean / `vite build` succeeds), so **none of these are caught by existing tests** - the tracker projection, the Reverb-down write path, and the frontend bundle/error-boundary gaps all pass CI while failing in real use.

Fix the P0 + the three stability/functional P1s (tracker, DELIVERED transition, broadcast-500, error boundary) before release. Security P1s (secret rotation, authz net) and the remaining P1s (bundle split, 404 page, buyer next-step, designer undo) should be triaged into the release or the first fast-follow.

### Severity summary

| Severity | Count |
|---|---|
| P0 blocker | 1 |
| P1 major | 8 |
| P2 minor | 12 |
| P3 polish | ~14 |

---

## P0 - Blocker

| # | Pillar | File:line | Defect | Impact |
|---|---|---|---|---|
| P0-1 | functional | `app/Models/Quote.php:202-213` | `trackingStage()` compares enum **instances** from `jobs()->pluck('state')` (`JobState::Closed`) against a **string** `JobState::Closed->value` (`'CLOSED'`) → comparison always false; the `every()` checks are dead code. **Additionally** no code path ever performs the `READY→CLOSED` quote transition, so the alternate DELIVERED route is also unreachable. | Public `/track` order tracker **never advances past "In production"** - a fully shipped & closed order still reports IN_PRODUCTION. The final leg of the buyer journey (Shipped → Delivered) is invisible to customers. Broken core, customer-facing. *(Note: the prior polish pass' "Mark delivered" label fix was cosmetic - the stage projection underneath it is what's broken.)* |

---

## P1 - Major

| # | Pillar | File:line / endpoint | Defect | Impact |
|---|---|---|---|---|
| P1-1 | stability | `app/Services/QuoteService.php:174,183` (+ all `ShouldBroadcastNow` sites); no `BroadcastException` handler in `bootstrap/app.php` | State transition commits to DB, then `broadcast()` throws (`Pusher cURL error 7`) unhandled → **HTTP 500** while the write already persisted. `launch.json` omits Reverb, so the app 500s on every staff/buyer write out-of-the-box. | With Reverb down/blipping, every write returns 500 but silently succeeds → client/server divergence + duplicate-submit risk. Prod runs Reverb under Supervisor (usually up) → P1 not P0. |
| P1-2 | stability | `frontend/src/main.tsx:11`, `frontend/src/App.tsx` | No `ErrorBoundary` anywhere in `src` - `<App/>` rendered bare. | Any render-time throw (bad API shape, lazy-chunk load failure, null field) unmounts the entire SPA to a blank white screen with no recovery. |
| P1-3 | performance | `frontend/src/App.tsx:6-21`, `ProductDesignerPage.tsx:5` → `DesignerCanvas` | fabric.js (**309 kB**) + all 15 routes statically bundled into the **851 kB** entry chunk (259 kB gzip); only ModelViewer is `lazy`. | Every first-time visitor incl. public home/catalogue downloads + parses fabric and all staff-only pages before first paint. Route-level `React.lazy` + `manualChunks` would shave ~300 kB from entry. |
| P1-4 | security | working-tree `.env` (lines 27-28, 35-36, 47-49, 60-69) | Live-looking secrets present in the shared checkout: DO Spaces key+secret (real bucket `nexgenbackup`), Reverb app secret, Stripe keys+webhook secret, Thingiverse/Cults3D tokens. **Verified never git-committed** (`git log --all -S`) and `.env` is gitignored. | Anyone with repo/machine access reads prod-adjacent secrets. **Rotate the DO Spaces key, Reverb secret, and Thingiverse/Cults3D tokens before release**; confirm CI/prod inject via vault, not a checked-out `.env`. |
| P1-5 | security | `QuoteController::store` (`routes/api.php:69`) + `QuoteService::create` | Quote-create tenancy is enforced **only** by `StoreQuoteRequest::withValidator` - there is no `QuotePolicy::create` and no `$this->authorize()`. Currently safe (FormRequest holds), structurally fragile: refactor the request and the controller has zero authz net. | Defense-in-depth gap on the create surface. Not currently exploitable. Add `QuotePolicy::create` + `authorize()`. |
| P1-6 | usability | `frontend/src/App.tsx:72` (`<Route path="*">`) | Any unknown route silently `<Navigate to="/" replace>` - no 404 page. | A buyer following a broken/stale deep link lands on Home with no explanation and assumes the site is broken. |
| P1-7 | usability | `frontend/src/pages/QuoteDetailPage.tsx:198-232` | Buyer-side renders a "Next step" card only for `SENT` and `PROOF_APPROVED`. In `CHANGES_REQUESTED`, `PROOFING`, `PO_ISSUED`, `CONFIRMED`, `PROCURING`, `READY` the buyer sees timeline + items but **no status explanation**. After "Request changes" there is zero confirmation of what happens next. | Buyer is left guessing whose court the ball is in → support tickets, repeated re-checks. |
| P1-8 | usability | `frontend/src/components/DesignerCanvas.tsx` | Studio has snap/nudge/size-bands/layer-order/delete/print-area but **no undo/redo** and delete is toolbar-only (no `Delete`/`Backspace` key). | The one destructive action (delete/replace) can't be reversed → a mis-drop forces re-upload + re-placement. Biggest studio friction. |

---

## P2 - Minor

| # | Pillar | File:line / endpoint | Defect |
|---|---|---|---|
| P2-1 | functional | `CheckoutPage.tsx`, `quoteStore.ts:80` `createQuote(companyId, lines, notes)` | Designer "Need it by" date + checkout address are **not persisted** to the quote (createQuote takes only company/lines/notes). Buyer's required-by deadline never reaches staff; lead-time shown in the designer is cosmetic. |
| P2-2 | security | `UploadController::artwork:20` + `ArtworkUploadRequest` | **Public, unauthenticated** `/uploads/artwork` (throttle 60/min) writes to the default `s3` disk (public DO Spaces bucket), world-readable URLs. Type/size validated (png/jpg/webp, 10 MB); SVG excluded. Storage/cost-DoS + orphaned anon uploads. Tighten throttle/daily-cap, consider non-public disk + signed URLs + cleanup. |
| P2-3 | performance | `.env` `QUEUE_CONNECTION=sync` + all `app/Events/*` `ShouldBroadcast` | Reverb HTTP publish runs synchronously inline in the write path (root cause behind P1-1's latency coupling). Run a queue worker (redis/database) in prod so broadcasts are async. |
| P2-4 | security | `QuoteController::store:46` `$request->array('line_items')`, `amend:66` | The originally-flagged "raw `->array()` instead of `validated()`" - **confirmed cosmetic, NOT exploitable**: FormRequest validation runs & passes before the controller; price is server-computed (`PricingService`); `QuoteService` reads named keys only. Switch to `->validated()` for clarity. |
| P2-5 | usability | `quoteStore.ts:172-189` `payNow` + `QuoteDetailPage.tsx:224` | Immediate-capture success path fires no toast (no `successMsg`) - inconsistent feedback on the single most important buyer action (money); buyer may re-click. |
| P2-6 | usability | `QuoteDetailPage.tsx:181-192` | "Request changes" sends a hardcoded `'Please revise.'` with no text field - staff get a content-free change request and must chase the buyer offline. |
| P2-7 | usability | `CatalogueAdminPage.tsx:351-361` | `cannot_publish_reasons` render as raw machine tokens (`missing_model_file`, `estimates_unverified`); `PENDING`/`CANNOT_PUBLISH` non-3D rows show blockers but an **empty Action cell** - no path forward. Map tokens to labels + add a hint. |
| P2-8 | usability | `QuoteDetailPage.tsx:243-335` | Proof artwork / PO refs are free-text "object-store key" inputs with no validation - a typo'd key silently yields a broken "View artwork" link. |
| P2-9 | usability | `TrackPage.tsx` | On a valid-but-not-found lookup the raw API `message` may surface (validation jargon) instead of the friendly fallback. Prefer the fallback on 404/422. |
| P2-10 | a11y (touch) | `CataloguePage.tsx:225-240` `CategoryChip` | Filter chips **34px** tall on mobile (meets 2.5.8 AA 24px, below 2.5.5 AAA 44px) - primary catalogue filters. |
| P2-11 | a11y (touch) | `CataloguePage.tsx:186-204` pagination `size="sm"` | Previous/Next **32px** tall on mobile. |
| P2-12 | a11y (touch) | `DesignerCanvas.tsx:437-446,558-580` floating `IconButton` `h-8 w-8` | 32×32 layer toolbar (bring-forward/send-backward/delete). Space-constrained over canvas, has `aria-label`s + keyboard nudge → **acceptable-with-caveat**. |

---

## P3 - Polish

- **`DashboardPage.tsx:63,85`** - pipeline/`byState` render raw enum strings (`PROOF_APPROVED`, `CHANGES_REQUESTED`) instead of `humanizeState()`. (consistency)
- **Terminology drift** - "Catalogue Gate" (nav) vs "Catalogue gate" (h1) vs `/catalogue-admin`; buyer "My Orders" (header) → page titled "Quotes". Settle one label per concept.
- **`KitBuilderPage.tsx:103-107`** - no login prompt in the kit flow; the login gate only surfaces at checkout (cart persists, recoverable).
- **`docs/API.md:178`** - cancel-state doc stale (code correctly allows Draft…Procuring → Cancelled). Doc drift.
- **`docs/API.md:19`** - `POST /login` returns full user (`email_verified_at`, timestamps, `deleted_at`) vs spec's `{id,name,email,role,company_id}`. Minor over-exposure / doc drift.
- **CORE seeder** - CORE products have `image_url: null` despite `storage/app/public/products/core-*.jpg` serving 200 → CORE PDPs show placeholder art.
- **`quoteStore.ts:204,233`** - `company.{id}` uses raw `getEcho().private()`/`leave()` instead of the `joinSharedPrivate`/`leaveSharedPrivate` refcount helpers. Safe today (single listener); reintroduces the teardown race if a second store ever listens. Robustness.
- **`ProductDetailPage.tsx:132-150`** - tier-price effect deps on `product` object identity; depend on `product?.id`. (guarded, no correctness bug)
- **`QuoteService.php:50-51`** - `Product::findOrFail` + `Variant::find` in a per-line loop on quote create (bounded by cart size; the estimate controller already batches with `whereIn`).
- **Touch targets (desktop / minor)** - SiteHeader "Categories" 36px, hero search + PDP secondary CTAs 40px, footer links 16px.
- **Config caveat** - ensure prod ships `APP_DEBUG=false` (dev `.env` has `APP_DEBUG=true`, `APP_ENV=local`).
- No password-complexity/rotation rules or account lockout beyond login rate-limit (6/min) - acceptable for internal B2B, noted.

---

## Per-pillar summary

- **Functional** - Both journeys drive end-to-end live, BUT the buyer order tracker is permanently broken (P0), and the DELIVERED quote transition is unreachable. "Need it by" + address are dropped at checkout (P2). Backend Pest 156-pass but never asserts the `/track` projection.
- **Stability** - Two release-relevant frontend/backend gaps: no ErrorBoundary (blank-screen) and unhandled broadcast → 500-on-write when Reverb is down. Reverb/echo refcount lifecycle is otherwise solid and unit-tested.
- **Security** - Strong. IDOR on `/track` (code + email-hash + generic 404 + throttle), broadcast tenancy, price/margin tampering, Sanctum/CSRF/CORS, XSS (zero sinks), cost-leak all **verified clean**. Real items: rotate exposed `.env` secrets, add a policy net on quote-create, throttle the public artwork upload.
- **Performance** - Backend is deliberate and clean (indexing, eager-loading, request-memoized pricing cache, pagination - no N+1 in read paths). The two items are frontend: eager 851 kB bundle + no error boundary. `QUEUE_CONNECTION=sync` broadcast coupling is a prod-config item.
- **Usability** - Disciplined build: near-universal loading/empty/error/success states, single-flight guards, accessible drawers. Gaps are edge-state/copy: missing 404 page, buyer's blank non-actionable quote states, content-free "Request changes", no designer undo.
- **Cross-device / a11y** - **No P0/P1.** No horizontal overflow at 375/768/1280 on buyer + staff surfaces; keyboard nav + focus traps + Escape + focus-restore verified; dark-mode surfaces opaque (RGB-triple token fix holds); reduced-motion dual-layered. Only P2/P3 touch-target sizing remains.

## Recommended fix order (release-blocking set)

1. **P0-1** - fix `trackingStage()` enum comparison (`->value` on both sides or compare enum-to-enum) **and** add the `READY→CLOSED` transition on final job close. Add a Pest test asserting `/track` returns SHIPPED then DELIVERED.
2. **P1-1** - wrap broadcast dispatch so a Reverb outage cannot 500 a committed write (try/catch + log, or move to a queued connection); add Reverb to `launch.json` for dev parity.
3. **P1-2** - add a top-level + route-level `ErrorBoundary`.
4. **P1-4** - rotate the exposed secrets; confirm prod secret injection.
5. Triage P1-3 (bundle split), P1-5 (authz net), P1-6/7/8 (404, buyer next-step, designer undo) into release or fast-follow.

## Method caveats

- **Live infra:** MySQL `giftlab` seeded with 4 CORE + 152 MODEL_3D products (real scraped 3D data). functional-e2e started Reverb on :8080 (owned the server that pass) to get past the broadcast-500 wall, then stopped it. Login throttle (6/min) tripped from prior sessions and was cleared via `RateLimiter::clear` (the "Session store not set" symptom manifested as 429).
- **Headless focus:** programmatic `.focus()` moves `document.activeElement` but does not trigger `:focus-visible` CSS without OS window focus - ring *visibility* verified via parsed CSS rule + token resolution; focus *management* verified behaviorally.
- **Screenshots/rAF** avoided per environment constraints; geometry/computed-style used instead.

## Non-goals (per design)

Bugs were reported, **not fixed** - remediation is a separate follow-up pass. No tests authored, no deps changed, no physical-device testing (viewport emulation only).
