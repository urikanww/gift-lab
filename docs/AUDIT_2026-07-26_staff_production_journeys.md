# Audit — Internal Staff & Production Staff Journeys

**Date:** 2026-07-26
**Scope:** Backend (Laravel) + frontend (React/TS) for the two operator journeys, plus a live run of the actual app (boot, log in as staff, walk the screens).
**Method:** 8 parallel code-reader passes (each traced screen → store → API → controller → service → response), synthesized and de-duplicated; every finding cited to real `file:line` and confirmed in code, not from docs. Plus a live boot + click-through that surfaced two runtime-only issues the static read cannot rank.

**Excluded by design:** 3D filament is intentionally **not** returned to stock on cancel (product decision — defect output makes exact tracking impractical). Not a bug; not listed.

---

## A. Runtime-only findings (from the live run — highest priority)

These only appear when the app actually runs; the static audit could not surface them.

### A1. [CRITICAL] A missing/wrong Reverb key white-screens the ENTIRE staff console
- **Where:** `frontend/src/lib/echo.ts:42-51` (`new Echo({ key: import.meta.env.VITE_REVERB_APP_KEY, ... })`), consumed by `frontend/src/components/StaffProofAlerts.tsx:22` which mounts unconditionally in `StaffLayout`.
- **Live evidence:** with no `VITE_REVERB_APP_KEY`, Pusher throws `"You must pass your app key when you instantiate Pusher"` synchronously during the staff shell's mount; the `ErrorBoundary` catches it and replaces the whole staff area with a "Reload page" screen. Every staff/production page is unreachable.
- **Impact:** any deploy where the realtime key is unset, wrong, or the config fails to load takes the entire operator UI down — not just live updates. Realtime is a nice-to-have; it should degrade, not nuke the app.
- **Fix:** wrap `getEcho()` construction in try/catch and make `joinSharedPrivate`/subscribers no-op (log once) when Echo can't init; or guard on a present key and render the app without live toasts. Realtime failure must never reach the ErrorBoundary.

### A2. [HIGH] Order-detail render does blocking HTTP self-requests to build proof composites
- **Where:** proof composite generation invoked during `GET /api/quotes/{ref}` serialization (`ProofResource` → `ProofCompositeService`); backend log shows repeated `cURL error 28: Operation timed out after 10008 ms ... http://localhost:8000/storage/products/core-8.jpg`.
- **Live evidence:** opening a PROOFING order fetches each proof's composite by making an **outbound HTTP request to the app's own `/storage`**, each with a 10s timeout. With images missing (or the web tier busy) the detail page hangs/blank-mains until every fetch times out; under a single-worker server it deadlocks outright.
- **Impact:** the staff order-detail screen — the hub of the whole journey — is slow and fragile; one unreachable image stalls it 10s per proof. Fragile in prod too (self-request + hard 10s timeout on the request thread).
- **Fix:** read the source image off the disk/filesystem directly instead of an HTTP round-trip to self; generate composites out-of-band (queue) or cache; shorten the timeout and render the raw artwork immediately with the composite filled in async.

### A3. [LOW] `/login` still renders the sign-in form while already authenticated
- **Where:** `LoginPage` / route guard. **Live evidence:** visiting `/login` when logged in shows the form (plus the authed nav). **Fix:** redirect authenticated users to `/dashboard`.

> Ruled OUT as product bugs (test-environment artifacts, verified): single-threaded `php artisan serve` deadlock on the self-request (prod uses php-fpm); session dropping on a dev-server restart; a `GET /api/quotes/{ref}` → 404 that turned out to be a genuine 404 for a ref not in that server instance's DB — the frontend's inline-error-plus-Retry handling of it is *correct*.

---

## B. Static audit (backend + frontend, 24 findings de-duped)

## Internal staff journey

- **[HIGH][unhandled-error] Quote-create validation errors are discarded; buyer sees a useless generic message** — `frontend/src/pages/CheckoutPage.tsx:200` — `placeOrder()` ignores the store's `actionError` (the real 422 reason from `apiError`, `quoteStore.ts:212-238`) and hardcodes a generic "review your cart and try again". — Server-only rejections (unpublished product, raised MOQ, removed CORE variants, stale `artwork_ref`, past `needed_by`) give the buyer no line or reason, and the persisted cart reproduces the failure forever. — Prefer `actionError` over the generic string; keep the generic only as fallback.

- **[HIGH][bug] Proof-staging row shown for `artwork_ref`-only lines that the backend rejects** — `app/Models/LineItem.php:128` (+ `frontend/src/pages/QuoteDetailPage.tsx:231`) — `needsProof()` returns true only when `customization['mode']` is set, but the frontend `lineNeedsProof` also returns true on `artwork_ref`/`reference_refs`, so `stageProof` (`QuoteService.php:581`) throws 422. — For that data shape staff see a working-looking file input that always fails; the line can never be proofed and the order can't advance. — Make `needsProof()` treat a non-dropped line with `mode` OR `artwork_ref` OR non-empty `reference_refs` as needing a proof.

- **[MEDIUM][unhandled-error] Duplicate PO reference (422) is invisible while the commit modal is open** — `frontend/src/pages/QuoteDetailPage.tsx:1128` — the commit modal renders no body; a dup `po_ref` (`IssueInvoiceRequest.php:26` `unique`) sets `actionError` but only the page-top banner (behind the overlay) shows it. — Staff typo/reuse a PO, click Commit, nothing visibly changes, and they re-click into the same 422. — Render `actionError` inside the modal body, or map the field error onto the PO input.

- **[MEDIUM][ux-gap] "Drop item" is a silent no-op for non-superadmin staff in proofing states** — `frontend/src/pages/QuoteDetailPage.tsx:557` — `onDrop` sets `editingLines=true`, but the editor only renders when `canEditLines` (`isStaff && DRAFT` or superadmin, line 173), false for a staff_admin in ACCEPTED/PROOFING/CHANGES_REQUESTED. — Regular staff click "Drop item" and get no editor, no error, no feedback. — Gate the control on `canEditLines`, or explain a post-send drop needs a superadmin.

- **[LOW][ux-gap] Buyer has no "request changes" action on a SENT quote, yet staff copy promises one** — `frontend/src/pages/QuoteDetailPage.tsx:1038` — the SENT buyer card renders only "Accept quote"; the DRAFT send helper (620-623) says the buyer can "accept it or request changes", and the state machine allows SENT→ChangesRequested. — Buyer who disputes pricing dead-ends until contacting staff out of band. — Add a buyer "Request changes" action or correct the send copy.

## Production staff journey

### Production queue / floor

- **[HIGH][unhandled-error] Advance / scan failures are silently swallowed — no toast, no banner** — `frontend/src/stores/queueStore.ts:93` — `advance()`/`advanceNext()` set `error` then immediately call `fetchQueue`, whose first line resets `error:null` (line 68); `onScan` reads `store.error` afterward so it's always null and the "surface the 422 SHIPPED-guard" toast is dead code. — An operator scanning an IN_PRODUCTION job, or hitting any wrong-state 422/500, gets zero feedback and assumes it worked. — Re-throw after refetch (like `createShipment`) and toast in the page catch, or capture the message before `fetchQueue`.

- **[HIGH][ux-gap] Realtime-arrived jobs have no print-file download and no customization view** — `frontend/src/stores/queueStore.ts:180` — the `ProductionQueueUpdated` payload omits `artwork_refs`/`line_items`; for a newly "queued" job `existing` is undefined so both are undefined with no refetch, and the buttons only render on those fields. — On the no-polling live board, any job that becomes READY after load shows no "Download print file" (the primary floor action) until a manual reload. — On a "queued" broadcast for an absent job, trigger a silent `fetchQueue()`, or include the fields in the payload.

- **[MEDIUM][security] Production-scoped operators get 403 on STL part downloads and the 3D preview** — `frontend/src/pages/ProductionQueuePage.tsx:743` (+ preview :773) — the part/model routes are gated by `permission:products.view` (`routes/api.php:194,198`) while the queue is `production.*`; a user granted only `production.*` (supported, `UserPermissionsTest.php:295`) is 403'd. — A production-only operator opening a 3D job can't load the preview or download the per-part STLs they must print. — Gate the floor model/part streams behind `production.view` (or accept either).

- **[MEDIUM][ux-gap] "Create NinjaVan shipment" is disabled with no stated reason until the address panel is manually opened** — `frontend/src/pages/ProductionQueuePage.tsx:415/421` — the button is gated on a client-only `addressReady` Set, populated only by expanding `DeliveryAddressPanel`; on fresh load it's empty even for jobs with a valid saved address, with no hint. — Operators see a greyed-out primary action and can't tell what unblocks it. — Fetch address-readiness on card render, or add helper text.

### Procurement desk

- **[MEDIUM][ux-gap] "Accept as-is" does two opposite things by reason, and its financial consequence is never labeled** — `frontend/src/pages/ProcurementPage.tsx:165` — QTY_SHORT cuts qty to `procured_qty` and retotals down; PRICE_JUMPED absorbs the increase into margin (`QuoteService.php:936-955`), neither stated on the card. — Staff can't tell the same button quietly performs a quantity cut vs a margin write-off. — Label the per-reason consequence; consider a confirm for the price-absorb case.

- **[MEDIUM][bug] Success/failure toast inferred from a global alert count the page's own subscription mutates → false "Could not resolve" errors** — `frontend/src/pages/ProcurementPage.tsx:38` — `run()` treats `alerts.length` shrinking as success, but the live `.awaiting-reconfirm` listener adds an alert; a new blocked line arriving mid-await keeps length equal so a genuine success shows a red toast. — A successful amend/approve/drop is reported as failure, prompting duplicate re-attempts. — Have `store.reconfirm()` return the real HTTP outcome and drive the toast off that.

- **[MEDIUM][ux-gap] "Accept as-is" stays enabled when nothing could be procured (`procured_qty < 1`)** — `frontend/src/pages/ProcurementPage.tsx:165` — the backend rejects approve with `procured_qty < 1` (`QuoteService.php:913-917`), but the button is only disabled by the single-flight guard. — For a zero-procurable line the prominent CTA errors on click; staff must read the 422 to learn they should have pressed Drop. — Disable when `(procured_qty ?? 0) < 1` and point to Drop.

- **[MEDIUM][unclear-next-action] No next step or link back to the order after a line resolves; the stock-confirmation gate is invisible** — `frontend/src/pages/ProcurementPage.tsx:43` — success only toasts "Line #X re-procured" and empties to a static `EmptyState`; resolving does not release to the floor (`tryQueue` needs `stock_confirmed_at`, `QuoteService.php:1041`) and all-dropped orders auto-cancel silently. — Staff get no signal the order still sits in PROCURING awaiting manual stock confirmation, nor any way to reach it. — Surface the order ref as a link with the next action.

- **[MEDIUM][ux-gap] Procurement page ignores the store `loading` flag** — `frontend/src/pages/ProcurementPage.tsx:80` — render is `alerts.length===0 ? EmptyState : list`; during initial fetch the operator sees "No lines awaiting reconfirmation" (may conclude there's no work); on fetch failure they see the error banner AND the empty state at once. — Gate `EmptyState` on `!loading && !error && alerts.length===0` (mirror ProductionQueuePage).

- **[LOW][bug] Amend that re-procures back into AwaitingReconfirm still clears the alert and shows "success"** — `frontend/src/stores/procurementStore.ts:116` — the store removes the alert on any 2xx ignoring `line_state`; re-appearance relies solely on the realtime broadcast. — Staff get a green "re-procured" for a still-blocked line; if the socket drops it silently vanishes. — Decide removal from the response `line_state` (or re-fetch).

- **[LOW][ux-gap] Reason badge renders the raw enum tag** — `frontend/src/pages/ProcurementPage.tsx:109` — `<Badge>{a.reason}</Badge>` prints `qty_short`/`price_jumped` verbatim. — Map to "Quantity short" / "Price jumped".

## Cross-cutting (auth / errors / comms)

- **[HIGH][security] Staff realtime channels gated only at `isStaff()`, leaking procurement cost/margin** — `routes/channels.php:28` (also :24) — `staff.queue`/`staff.procurement` authorize on `isStaff()` alone while the HTTP routes require `permission:production.view`/`procurement.view` (`api.php:169,173`); `LineItemAwaitingReconfirm` carries `unit_price`/`procured_price`, and StaffLayout subscribes every staff user (`dashboardStore.ts:51,56`). — A staff_admin 403'd from the desks over HTTP still receives cost/margin, order refs and proof notes over the websocket. — Gate the channel callbacks on the same granular permissions.

- **[HIGH][ux-gap] Shipment sends NO buyer email — the "On its way" milestone with tracking never fires** — `app/Services/QueueService.php:231` — `advance()` flips the job to `JobState::Shipped` and stores `consignment_ref`+carrier but never calls `OrderNotifier`; `OrderMilestone::Shipped` has full copy, defaults ON, and is advertised in settings yet has no send site. — The milestone customers care about most (tracking) is captured but never emailed. — On `JobState::Shipped`, send `OrderMilestone::Shipped` with the consignment ref, once per order.

- **[MEDIUM][ux-gap] Operational routes guarded by `staffOnly` instead of granular permission → restricted staff_admin deep-links into a perpetual 403** — `frontend/src/App.tsx:179` (and :180-186,192) — production-queue/procurement/reorders/catalogue/product-admin/notifications use only `staffOnly`, while Pricing/Users use `permission=` guards that redirect. — A restricted staff_admin who bookmarks e.g. `/procurement` mounts a page whose API 403s onto an `ErrorState` whose Retry loops on 403. — Switch these routes to the matching `permission=` guards.

- **[MEDIUM][bug] "Revised proof issued" toggle is a dead switch — proof emails ignore it and always send** — `app/Services/QuoteService.php:664` — `emailProofsReady()` sends `QuoteReadyMail` unconditionally, never consulting `isEnabled(OrderMilestone::ProofIssued)`, yet settings render it as a live toggle. — Staff who disable proof emails to handle the conversation personally still have mail go out every round. — Gate `emailProofsReady` on the toggle, or remove it from settings; fix the description.

- **[LOW][ux-gap] "Item changed or dropped" toggle does nothing when switched ON — no line-change email exists** — `app/Enums/OrderMilestone.php:28` — `LineChanged` has copy and defaults OFF but is never sent from any path, while settings render it as an interactive toggle. — Drop `line_changed` from settings (or render read-only), or wire the send into the drop/reprice path.

---

## Top fixes now

1. **Guard realtime init so a missing Reverb key can't white-screen the staff console** — `echo.ts:42` / `StaffProofAlerts.tsx:22` (A1 — whole operator UI down).
2. **Surface the real quote-create error instead of the generic string** — `CheckoutPage.tsx:200` (buyer permanently stuck).
3. **Fire the buyer Shipped email with tracking on `JobState::Shipped`** — `QueueService.php:231` (highest-value milestone, entirely missing).
4. **Gate `staff.queue`/`staff.procurement` channels on granular permissions** — `channels.php:28` (cost/margin leak over websocket).
5. **Align `LineItem::needsProof()` with the frontend** (`mode` OR `artwork_ref` OR `reference_refs`) — `LineItem.php:128` (order cannot advance for that data shape).
6. **Stop swallowing advance/scan errors; toast from the promise result** — `queueStore.ts:93` (floor operator gets no feedback).
7. **Build proof composites from disk / out-of-band, not a blocking self-HTTP fetch** — `ProofCompositeService` (A2 — slow, fragile order-detail render).
8. **Hydrate realtime-arrived jobs via silent `fetchQueue()`; render dup-PO 422 in the modal; drive procurement toast off the real HTTP outcome** — `queueStore.ts:180`, `QuoteDetailPage.tsx:1128`, `ProcurementPage.tsx:38`.
