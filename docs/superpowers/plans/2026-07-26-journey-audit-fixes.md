# Staff & Production Journey Audit Fixes — Implementation Plan

> **For agentic workers:** implement task-by-task. Each task is grouped by the file(s) it touches so tasks never edit the same file concurrently. TDD where a test can express the behavior; every task ends in a commit. Source: `docs/AUDIT_2026-07-26_staff_production_journeys.md`.

**Goal:** Fix the confirmed staff + production journey defects from the audit (backend Laravel + frontend React/TS), honoring three product decisions: price-stage buyer request-changes = fix wording only; notification toggles = make both real; post-send "Drop item" = hide for non-superadmin.

**Tech:** Laravel (PHP 8.3, Pest), React/TS (Vitest/RTL, Zustand). Runner: `vendor/bin/pest`, `npm --prefix frontend run test`.

**Conventions:** match surrounding code; do not weaken assertions; no unrelated refactors. Where a fix has a test, write it first, watch it fail, implement, watch it pass.

---

## Backend tasks

### Task B1 — Fire the buyer "Shipped" email with tracking
**Files:** `app/Services/QueueService.php` (~:231 advance), `app/Services/OrderNotifier.php`, test `tests/Feature/OrderNotificationTest.php` (or a new `ShippedEmailTest.php`).
- When `advance()` moves a job to `JobState::Shipped`, call `OrderNotifier->send($job->quote, OrderMilestone::Shipped, [...consignment_ref, carrier, tracking_url])`, guarded to fire once per order (do not re-send if already shipped/notified).
- Respect the milestone toggle (`isEnabled(OrderMilestone::Shipped)`), consistent with other sends.
- Read `OrderNotifier`/`OrderMilestoneMail` for the existing send signature + how tracking url is built (`Carrier::trackingUrl`).
- **Test:** advancing a job to SHIPPED queues `OrderMilestoneMail` with `milestone === OrderMilestone::Shipped`; advancing again does not double-send. Use `Mail::fake()`.

### Task B2 — Make both notification toggles real
**Files:** `app/Services/QuoteService.php` (~:664 `emailProofsReady`), the line drop/reprice path (`reconfirmLine` drop branch ~:957 and amend retotal), `app/Enums/OrderMilestone.php` (`LineChanged`), `app/Services/OrderNotifier.php`, tests in `tests/Feature/`.
- `emailProofsReady()` must consult `isEnabled(OrderMilestone::ProofIssued)` and NOT send when disabled (currently sends unconditionally). Fix the settings description if it says "after the first" (it fires on round one too).
- Wire `OrderNotifier->send($quote, OrderMilestone::LineChanged, [...])` into the line **drop** and **reprice (amend)** paths so flipping that toggle ON actually emails the buyer. Respect its enabled flag (defaults OFF).
- **Tests:** (a) proof-ready email is NOT queued when `ProofIssued` disabled, IS when enabled; (b) dropping/repricing a line queues `LineChanged` when enabled, not when disabled.

### Task B3 — Gate staff realtime channels on granular permissions
**Files:** `routes/channels.php` (~:24 `staff.queue`, ~:28 `staff.procurement`), test `tests/Feature/` (channel auth) — mirror any existing channel-auth test.
- Replace `return $user->isStaff();` with the same granular check the HTTP routes use: `staff.queue` → `production.view`, `staff.procurement` → `procurement.view` (confirm permission keys in `routes/api.php:169,173` and the User permission helper). Given the payloads carry cost/margin, requiring the desk's own view permission is the fix.
- **Test:** a staff_admin WITHOUT `production.view`/`procurement.view` is denied the channel; one WITH it is allowed. Follow the existing broadcast-auth test pattern if present; else assert via `Broadcast::channel` callback resolution.

### Task B4 — Align `LineItem::needsProof()` with the real proof rule
**Files:** `app/Models/LineItem.php` (~:123-130 `needsProof`), tests `tests/Unit/` or `tests/Feature/LineItemNeedsProofTest.php` (exists — extend it).
- `needsProof()` must return true for a non-dropped line whose `customization` has `mode` SET, **or** a non-empty `artwork_ref`, **or** non-empty `reference_refs` (currently only `mode`). This matches the frontend `lineNeedsProof` and lets `stageProof` succeed for artwork-only lines.
- Verify `stageProof` (`QuoteService.php:581`) then accepts these lines (its guard uses `needsProof`).
- **Test:** extend `LineItemNeedsProofTest` — an `artwork_ref`-only line and a `reference_refs`-only line both return true; a bare line (no mode/artwork/refs) returns false; a dropped line returns false.

### Task B5 — Serve production STL/preview to production-only operators
**Files:** `routes/api.php` (~:194,198 the model/part routes gated `permission:products.view`), test `tests/Feature/UserPermissionsTest.php` (extend).
- Change the floor-facing model + part stream routes to accept `production.view` (either via a combined middleware that passes on `production.view` OR `products.view`, or move them under the production permission). Confirm which routes the floor uses from `ProductionQueuePage.tsx:743,773`.
- **Test:** a user with only `production.*` can GET the part/model stream (200/redirect, not 403); keep `products.view` users working.

### Task B6 — Proof composite: stop the blocking self-HTTP fetch
**Files:** `app/Services/ProofCompositeService.php` (+ whatever fetches the product/artwork image), tests `tests/Feature/ProofCompositeTest.php` (exists).
- The composite currently fetches the source image via an HTTP request to the app's own `/storage/...` (10s timeout, blocks the request thread, deadlocks under one worker). Change it to read the image bytes directly from the storage disk (`Storage::disk(...)->get($path)`), deriving the disk path from the public URL, with a graceful fallback (raw artwork) when the file is absent — no outbound HTTP to self.
- Keep the existing fallback behavior on missing/failed images, but without the 10s network wait.
- **Test:** composite build for a line whose source image exists on the (faked) disk succeeds without any HTTP call; a missing image falls back fast (no 10s wait). Reuse `Storage::fake` + the existing test's setup.

> B6 is the riskiest change — read `ProofCompositeService` fully and the existing `ProofCompositeTest` before editing. If the URL→disk-path mapping is non-trivial, keep the HTTP path as a last-resort fallback but prefer the disk read.

---

## Frontend tasks

### Task F1 — Guard realtime init so a missing key can't white-screen the app
**Files:** `frontend/src/lib/echo.ts` (`getEcho`), `frontend/src/components/StaffProofAlerts.tsx` and any other `joinSharedPrivate`/`getEcho` callers, test `frontend/src/lib/echo.test.ts` (extend).
- In `getEcho()`, if `import.meta.env.VITE_REVERB_APP_KEY` is missing/empty OR `new Echo(...)` throws, catch it, log once, and return a null-object Echo (or have `joinSharedPrivate`/`leaveSharedPrivate`/subscribers become safe no-ops). The staff shell must render normally with live updates simply inactive — never reach the ErrorBoundary.
- **Test:** with no `VITE_REVERB_APP_KEY`, `getEcho()`/`joinSharedPrivate('staff.queue')` does NOT throw; subscribing is a no-op. (Mock Pusher to throw, as the existing test mocks Echo/Pusher.)

### Task F2 — Surface the real quote-create error at checkout
**Files:** `frontend/src/pages/CheckoutPage.tsx` (~:200 `placeOrder`), `frontend/src/stores/quoteStore.ts` (`actionError`, ~:212-238), test `frontend/src/pages/CheckoutPage.test.tsx`.
- In the `<ErrorState>`/failure branch, show the store's `actionError` (the real 422 reason) when present; keep the generic string only as a fallback.
- **Test:** when `createQuote` rejects with a 422 message, the checkout shows that message (not the generic one).

### Task F3 — queueStore: surface advance/scan errors + hydrate realtime jobs
**Files:** `frontend/src/stores/queueStore.ts` (~:68 fetchQueue error reset, ~:93 advance/advanceNext, ~:180 ProductionQueueUpdated handler), test `frontend/src/stores/queueStore.test.ts`.
- Advance/scan: capture the error message BEFORE calling `fetchQueue` (which resets `error:null`), or re-throw after refetch so the page/`onScan` can toast it (mirror `createShipment`). The wrong-state 422 must reach the operator.
- Realtime hydrate: when a `ProductionQueueUpdated` "queued" event arrives for a job not already in state (so `artwork_refs`/`line_items` are absent), trigger a silent `fetchQueue()` (or merge if the payload includes them) so the print-file download + customization view appear without a manual reload.
- **Tests:** (a) a failed advance leaves a non-null error / rejects so the caller can toast; (b) a "queued" event for an unknown job triggers a refetch.

### Task F4 — QuoteDetailPage: dup-PO error in modal, hide post-send Drop, fix copy
**Files:** `frontend/src/pages/QuoteDetailPage.tsx` (~:1128 commit modal, ~:557 onDrop / ~:173 canEditLines, ~:620-623 send helper copy), test `frontend/src/pages/QuoteDetailPage.test.tsx`.
- Dup PO: render `actionError` inside the commit modal body (and/or map onto the PO input) so the 422 is visible without closing the modal.
- Drop item: only render the "Drop item" control when `canEditLines` is true (hide for staff_admin in ACCEPTED/PROOFING/CHANGES_REQUESTED). Product decision: hide, don't newly permit.
- Copy: correct the DRAFT-send helper text so it does NOT promise a price-stage "request changes" (buyer accepts price, then can request changes at the proof stage). Keep it accurate.
- **Tests:** (a) with a commit `actionError` set, the modal body shows it; (b) the Drop control is absent for a staff_admin on a SENT/PROOFING quote.

### Task F5 — ProcurementPage + store: correct outcomes, labels, next-action, states
**Files:** `frontend/src/pages/ProcurementPage.tsx` (~:38 run/toast, :43 success next-step, :80 loading, :109 reason badge, :165 Accept-as-is), `frontend/src/stores/procurementStore.ts` (~:116 amend re-block, reconfirm return value), test `frontend/src/pages/ProcurementPage.test.tsx` + `procurementStore.test.ts`.
- Toast off real outcome: `store.reconfirm()` returns the real HTTP result (and the resulting `line_state`); drive success/failure toast off that, not `alerts.length`.
- Amend re-block: remove/keep the alert based on the response `line_state` (a line back in `AWAITING_RECONFIRM` stays), not on any-2xx.
- Accept-as-is: label the per-reason consequence (QTY_SHORT = quantity cut + retotal down; PRICE_JUMPED = absorb into margin); disable the button when `(procured_qty ?? 0) < 1` and point to Drop.
- Next-action: on success show the order reference as a link and the next step ("ready to confirm stock" / "cancelled — all lines dropped"); make the stock-confirmation gate visible.
- Loading + empty: gate `EmptyState` on `!loading && !error && alerts.length === 0`; show a loading state during the initial fetch.
- Reason badge: humanize `qty_short`/`price_jumped` → "Quantity short"/"Price jumped".
- **Tests:** (a) a genuine success after a concurrent new alert still toasts success (not failure); (b) an amend that returns `AWAITING_RECONFIRM` keeps the alert; (c) EmptyState is not shown while loading.

### Task F6 — Route guards + login redirect
**Files:** `frontend/src/App.tsx` (~:179-192 route guards), `LoginPage`/route for `/login`, tests in `frontend/src/` as applicable.
- Switch production-queue/procurement/reorders/catalogue/product-admin/notifications routes from `staffOnly` to the matching `permission=` guards (as Pricing/Users already do) so a restricted staff_admin is cleanly redirected to `/dashboard` instead of landing on a 403-looping page. Use the same permission keys the backend enforces.
- `/login`: redirect an already-authenticated user to `/dashboard`.
- **Test (light):** a `/login` visit while authenticated redirects; a restricted user hitting a guarded route redirects (if a test harness for routing exists — else verify via the guard prop and note it).

---

## Execution order (by risk/independence)
Backend B3, B4, B1, B2, B5 (behavior + tests), then B6 (riskiest). Frontend F1 (critical), F2, F3, F4, F5, F6. Each task edits a disjoint file set from its siblings except QuoteDetailPage (F4 only) and ProcurementPage (F5 only) and queueStore (F3 only) — so run frontend tasks sequentially to be safe.

## Coverage map
Every audit finding maps to a task: A1→F1, A2→B6, A3→F6, quote-create→F2, needsProof→B4, dup-PO→F4, drop-item→F4, buyer-copy→F4, scan-swallow→F3, realtime-hydrate→F3, prod-STL-perm→B5, ship-address-hint→(F5-adjacent; see note), accept-as-is (×2)→F5, proc-toast→F5, proc-next-action→F5, proc-loading→F5, proc-amend-reblock→F5, reason-badge→F5, channel-leak→B3, route-guards→F6, proof-toggle→B2, line-changed-toggle→B2, shipped-email→B1.
> Note: the "Create shipment disabled until address panel opened" finding (ProductionQueuePage) is folded into F3's scope if trivial; otherwise track as a follow-up — flag it rather than silently dropping.
