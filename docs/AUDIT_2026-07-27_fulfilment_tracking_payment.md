# Fulfilment / Tracking / Payment Audit

**Date:** 2026-07-27  
**Scope:** production-staff print → ship → NinjaVan → tracking page → B2B payment. 3 goals: tracking reflects every status understandably; order syncs with NinjaVan; no payment issue.

## G1 Tracking page (clarity + completeness)

### 1. [HIGH][tracking-completeness] Live tracking update drops the shipment link and item-count when an order flips to SHIPPED
- **file:line** — `frontend/src/pages/TrackPage.tsx:33`
- **evidence** — The Echo listener merges only `{ ...prev, stage, stage_label, cancelled, updated_at }` (TrackPage.tsx:33-37), and `OrderTrackingUpdated::broadcastWith()` carries only those same fields (`app/Events/OrderTrackingUpdated.php:55-61`). Neither carries `shipments` or `items_completed`, which are populated solely by the initial POST /track payload (`app/Services/OrderTracker.php:42`, `shipments()` :86-107, `itemsCompleted()` :70-78).
- **impact** — On the live no-reload path the NinjaVan tracking link, carrier name, consignment ref, and the "X of Y items shipped" line never appear until the buyer manually reloads. G1 explicitly requires the tracking number/carrier link to surface once shipped; it is silently withheld.
- **fix** — Add `shipments`, `items_completed`, `items_total` to `OrderTrackingUpdated::broadcastWith()` (reusing OrderTracker so the contract stays in one place) and merge them in the TrackPage listener; or on a SHIPPED/DELIVERED stage change, re-fetch the full /track payload instead of patching a partial event.

### 2. [MEDIUM][tracking-clarity] 'In review' collapses six distinct states, including ones where the BUYER must act
- **file:line** — `app/Models/Quote.php:431`
- **evidence** — `trackingStage()` maps everything unmatched to `REVIEW` → "In review" (Quote.php:431-435, labels :44-50). That bucket includes Draft, Sent, ChangesRequested, Accepted, Proofing, ArtworkApproved (`QuoteState.php:14-25`). Sent (awaiting acceptance), Proofing (proof out for approval), ArtworkApproved (buyer must accept pricing), and ChangesRequested are all buyer-blocked states. The OrderMilestone emails correctly say "please approve" (`app/Enums/OrderMilestone.php:79,87,98-101`) but the page shows a passive, seller-sounding label with no call to action.
- **impact** — For a proof/price-approval-centric gifting flow, the tracker gives no signal that production is stalled on the buyer; buyers wait instead of acting.
- **fix** — Split REVIEW into at least "Awaiting your approval" (Sent/Proofing/ArtworkApproved/ChangesRequested) vs "In review" (Draft/Accepted), or add a helper line describing whether the buyer needs to act. Internal enum names already stay out of the payload — keep it that way.

### 3. [LOW][tracking-completeness] 'placed_at' is returned in the payload but never rendered
- **file:line** — `app/Services/OrderTracker.php:37`
- **evidence** — `payload()` returns `'placed_at' => $quote->created_at?->toIso8601String()` (OrderTracker.php:37), declared in `frontend/src/types.ts:165` and the test fixture, but `TrackResultView.tsx:58-96` renders only needed_by, item counts, shipments, and updated_at. The page shows "Last updated" but no "Order placed on" date.
- **impact** — Minor completeness gap: buyer has no anchor date for when the order began, and dead data flows through the contract.
- **fix** — Render `placed_at` in TrackResultView ("Order placed {date}"), or remove it from the payload and type if omission is intentional.

### 4. [LOW][ninjavan-inbound-gap] No in-transit / out-for-delivery detail on the page between SHIPPED and DELIVERED
- **file:line** — `app/Models/Quote.php:400`
- **evidence** — After SHIPPED the only further on-page stage is DELIVERED, derived from jobs being CLOSED (Quote.php:400-428), set manually. NinjaVan's intermediate courier states have no entry in `TRACKING_STAGE_LABELS` (Quote.php:44-50) and no payload field. The buyer's only granular status is the external "Track with Ninja Van" deep link (`app/Enums/Carrier.php:36-49`).
- **impact** — On-page the order sits at "Shipped" until a human closes the job, then jumps to "Delivered". This is the tracking-page manifestation of the G2 inbound gap, mitigated only by the external link.
- **fix** — When inbound NinjaVan status is wired (G2), extend `TRACKING_STAGE_LABELS` and/or add a per-shipment status field so out-for-delivery, delivered, and failed-delivery show on the page in plain language.

---

## G2 NinjaVan sync (outbound + inbound gap)

### 1. [CRITICAL][ninjavan-inbound-gap] No NinjaVan inbound webhook route exists — only Stripe has one
- **file:line** — `routes/api.php:105`
- **evidence** — The route file declares exactly one unauthenticated carrier/payment callback: `Route::post('/stripe/webhook', …)` (line 105). A full grep shows no `/ninjavan`, `/courier`, `/track/webhook`, or `/delivery` route, and no matching controller exists.
- **impact** — NinjaVan's post-dispatch status changes never reach the app. Once a job is pushed at SHIPPED, the app is blind to everything after — an order can be delivered or returned-to-sender while the tracker still says "Shipped".
- **fix** — Add an unauthenticated, HMAC/signature-verified `POST /api/ninjavan/webhook` modeled on the Stripe pattern (line 105), with its own throttle limiter. Verify NinjaVan's signature before trusting the body; correlate to a ProductionJob by `consignment_ref`.

### 2. [CRITICAL][ninjavan-inbound-gap] No polling command or scheduled task pulls NinjaVan status
- **file:line** — `routes/console.php:1`
- **evidence** — The 16 commands in `app/Console/Commands` and the 8 scheduled entries in `routes/console.php` touch catalogue/artwork/giftideas/quotes only — none poll a carrier. The `CourierClient` contract (`app/Services/Courier/Contracts/CourierClient.php`) exposes only `createShipment()`; there is no `getStatus()`/`fetchTracking()` to poll.
- **impact** — Absent a webhook, a poll would be the fallback inbound path — it too is entirely missing. No code path of any kind reads a shipment's current NinjaVan status back into the app.
- **fix** — Prefer the webhook; otherwise add a scheduled `courier:sync-shipments` iterating SHIPPED jobs with a consignment_ref against NinjaVan's tracking/order-status endpoint. Requires first extending the CourierClient contract with a status-fetch method on both HttpNinjaVanClient and FixtureNinjaVanClient. Schedule with `onOneServer()` + `withoutOverlapping()`.

### 3. [CRITICAL][ninjavan-inbound-gap] DELIVERED is reached only by a manual staff scan, never by a real NinjaVan delivery confirmation
- **file:line** — `app/Services/QueueService.php:280`
- **evidence** — The only path to `JobState::Closed` is staff-driven — `advanceNext()`/`advanceBatch()`/`advance()` behind `permission:production.manage` (routes/api.php 174-176). `Shipped->nextStates()=[Closed]` (`app/Enums/JobState.php:25`), so a staff scan flips a shipped job to CLOSED; QueueService.php:280-301 closes the quote and `Quote::trackingStage()` maps `Closed → 'DELIVERED'` (Quote.php:406-408). Nothing consults NinjaVan.
- **impact** — The buyer-facing "Delivered" stage is asserted by a staff click, not real delivery. It can show "Delivered" before/without actual delivery, or stay "Shipped" long after.
- **fix** — Make terminal NV delivery the trigger: resolve ProductionJob by `consignment_ref` (== requested_tracking_number == `NinjaVanTrackingNumber::forQuote(quote.id)`, ShipmentService.php:54) and on NV "Completed"/"Delivered" call `QueueService->advance($job, JobState::Closed)` so quote-close, audit, OrderMilestone, and Broadcasting stay consistent. Guard with `canTransitionTo(Closed)` for webhook-retry idempotency. Retain a manual staff override.

### 4. [HIGH][ninjavan-outbound] Stored tracking number is the request value we sent, never reconciled with NinjaVan's response
- **file:line** — `app/Services/Courier/HttpNinjaVanClient.php:58`
- **evidence** — `createShipment` returns `new CourierShipmentResult($shipment->requestedTrackingNumber, …)`; the response body is parsed only for `label_url` (line 58) and auth (158,165) — the v4.1 response's `tracking_number` is never read (grep-confirmed). `ShipmentService.php:72` persists the echoed value as `consignment_ref`. Yet the code's own docs say NinjaVan mutates it: `NinjaVanTrackingNumber.php:9` ("WITHOUT the account prefix which NinjaVan prepends") and `config/services.php:132`.
- **impact** — G1: `OrderTracker.php:102` builds the buyer link as `Carrier::NinjaVan->trackingUrl($ref)` (Carrier.php:42); if NinjaVan prefixes the number the link resolves to nothing. G2: the future inbound sync will report NinjaVan's real tracking_number, and matching it to a missing-prefix `consignment_ref` will fail, silently dropping every inbound update. This is the single match key the whole inbound path hinges on.
- **fix** — Read the authoritative `tracking_number` (and `label_url`) from the create-order response and persist that as consignment_ref. On a live sandbox, confirm whether a supplied `requested_tracking_number` is used verbatim or prefixed, and store accordingly. Resolve the code-vs-comment contradiction. *(confidence: likely)*

### 5. [HIGH][ninjavan-inbound-gap] Tracker has no representation for intermediate NinjaVan statuses (picked-up / in-transit / out-for-delivery / failed / returned)
- **file:line** — `app/Models/Quote.php:44`
- **evidence** — `TRACKING_STAGE_LABELS` = REVIEW, CONFIRMED, IN_PRODUCTION, SHIPPED, DELIVERED (Quote.php:44-50); JobState has only READY/IN_PRODUCTION/SHIPPED/CLOSED (`JobState.php:11-15`). Between SHIPPED and DELIVERED there is one stage and no sub-status field: `production_jobs` has only `consignment_ref` + `carrier` (migrations 2026_07_03_000025, 2026_07_10_000001) — no `delivery_status`/`delivered_at`/`last_courier_event`. `OrderTracker::payload` (OrderTracker.php:22-44) emits a static stage list with no per-shipment live status.
- **impact** — Even once inbound data flows, there is nowhere to show it; the buyer sees a binary Shipped→Delivered.
- **fix** — Add nullable `last_courier_status` + `last_courier_status_at` + `delivered_at` to `production_jobs`, persist from the inbound handler, and surface per-shipment in `OrderTracker::shipments()` (:86-107). Add buyer-facing sub-stage labels so SHIPPED renders detail without adding JobStates.

### 6. [HIGH][ninjavan-inbound-gap] No app state for Delivery Failed / Returned-to-Sender / Cancelled — mapping is genuinely ambiguous
- **file:line** — `app/Enums/JobState.php:11`
- **evidence** — JobState is strictly linear Ready→InProduction→Shipped→Closed with Closed terminal and no failure/return/cancel state (JobState.php:20-28). `Quote::trackingStage()` knows CANCELLED only via `QuoteState::Cancelled` (Quote.php:402-404), a staff cancel path, not a courier outcome. NinjaVan has no notion of the app's internal production stages, so inbound sync must only act on already-SHIPPED jobs and never regress production state.
- **impact** — When NinjaVan reports RTS or repeated delivery failure, the app cannot represent it; the job is stuck at SHIPPED forever with no terminal-fail state to close the loop.
- **fix** — Decide and document terminal handling: (a) NV Delivery Failed → keep SHIPPED + `last_courier_status` flag + "Delivery attempt failed", optionally notify staff; (b) Returned to Sender → new terminal JobState (e.g. RETURNED) or route to the quote cancel/exception flow; (c) NV Cancelled → reconcile with `QuoteState::Cancelled`. Adding states requires updating `nextStates()` and every `transitionTo` consumer — scope deliberately.

### 7. [MEDIUM][ninjavan-outbound] Parcel weight is always the config default (1kg); never derived from the order, and parcelCount is dropped
- **file:line** — `app/Services/Courier/HttpNinjaVanClient.php:117`
- **evidence** — `parcel_job.dimensions.weight` is hardcoded from `config('services.ninjavan.default_weight_kg', 1)` (lines 116-118; config default 1 at config/services.php:138). `CourierShipment` has no weight field (CourierShipment.php:9-24) and `ShipmentService` never computes weight from line items (ShipmentService.php:58-65). `parcelCount` is on the DTO (CourierShipment.php:20, set to 1 at ShipmentService.php:63) but never placed in the payload — a multi-item job books as one parcel.
- **impact** — Every consignment books at a flat 1kg regardless of contents, which can mis-rate the shipment and, where weight drives service eligibility, cause NinjaVan to reject or mis-handle heavier orders. The "what if the quote has no weight?" case: the app never looks at order weight at all.
- **fix** — Add a weight field to CourierShipment, compute from line-item/product weights (fall back to config default only when unknown), and send it. Decide whether parcelCount should map to a parcel quantity or stay 1.

### 8. [MEDIUM][ninjavan-outbound] Courier booking happens outside the DB transaction; a DB failure after a successful remote booking leaves the job permanently unshippable
- **file:line** — `app/Services/ShipmentService.php:67`
- **evidence** — The billable courier call is at line 67, before the `DB::transaction(...)` at line 69 that persists SHIPPED + consignment_ref. If the call succeeds but the transaction then fails (e.g. transitionTo save or audit insert in QueueService.advance throws), consignment_ref stays null. The idempotency guard (ShipmentService.php:38) keys only on consignment_ref, so a retry passes it, regenerates the same deterministic tracking number (NinjaVanTrackingNumber.php:16), and re-calls NinjaVan, which per the code's own assumption (ShipmentService.php:36-37) rejects the duplicate and throws.
- **impact** — The parcel is physically booked but the job is stuck IN_PRODUCTION forever: SHIPPED never reached, the "on its way" email (QueueService.php:277) never fires, the tracker never advances. Probability is bounded (OrderNotifier and Broadcasting swallow their own exceptions, so only a genuine mid-transaction DB failure triggers it) but the failure mode is unrecoverable.
- **fix** — Persist a "booking in progress"/requested_tracking_number marker before the courier call so a retry can detect an already-attempted booking, or make the guard tolerant of a booked-but-uncommitted state (treat a re-sent number NinjaVan reports as already-existing as success and complete the SHIPPED transition). *(confidence: likely)*

### 9. [MEDIUM][ninjavan-inbound-gap] No inbound-webhook authentication is configured for NinjaVan
- **file:line** — `config/services.php:119`
- **evidence** — The ninjavan config block (config/services.php:119-144) has client_id/secret/base_url and dispatch tuning but no webhook secret / HMAC key / allowed-signature config — consistent with there being no inbound handler. The Stripe path by contrast verifies a signing secret in its controller.
- **impact** — The forthcoming webhook will receive unauthenticated external requests that flip order state to Delivered/Closed. Without signature verification, anyone who guesses a tracking number could forge a "delivered" event.
- **fix** — Add a `webhook_secret` (and/or NinjaVan's HMAC scheme) to the ninjavan config block and verify it in the new webhook controller before dispatching any state change, mirroring StripeWebhookController. Fail closed on missing/invalid signature.

### 10. [LOW][ninjavan-outbound] NinjaVan-returned label_url is fetched then silently discarded
- **file:line** — `app/Services/ShipmentService.php:69`
- **evidence** — `HttpNinjaVanClient.php:58` parses `$resp->json('label_url')` into `CourierShipmentResult.labelUrl` (CourierShipmentResult.php:12), but ShipmentService.php:69-74 passes only `consignmentRef` and `carrier` to `queue->advance`; labelUrl is never read. `ProductionJob` has no label column (ProductionJob.php:34-45).
- **impact** — The printable waybill URL is thrown away; staff cannot reprint or retrieve the shipping label from the app after booking.
- **fix** — If NinjaVan's v4.1 response returns a label URL, add a column and persist it alongside consignment_ref; otherwise drop the dead labelUrl plumbing.

### 11. [LOW][ninjavan-outbound] Ship-to is only null-checked, not validated for completeness
- **file:line** — `app/Services/ShipmentService.php:42`
- **evidence** — ShipmentService.php:42-45 guards only `$addr === null`. Required fields (recipient_name, phone, postal_code, country, line1) pass straight through (:60-62 → HttpNinjaVanClient.php:95-106) with no non-empty check. `deliveryStartDate` uses `$quote->needed_by?->toDateString()` (:55), which can be a past date. The HTTP retry callback (HttpNinjaVanClient.php:72-77) retries only connection/5xx faults, so a NinjaVan 400 (bad address / past delivery_start_date) is not retried.
- **impact** — A present-but-blank address row or stale needed_by yields a NinjaVan 400 → CourierException → opaque 502 to staff (ProductionQueueController.php:186). Fails loud, leaves no bad state, but staff cannot self-diagnose.
- **fix** — Validate required ship-to fields and clamp delivery_start_date to >= today before the billable call, returning a specific 422 so staff can fix the address instead of an opaque 502.

### 12. [LOW][ninjavan-outbound] Outbound push path verified correct — booked once at SHIPPED with idempotency backstops (no change needed)
- **file:line** — `app/Services/ShipmentService.php:38`
- **evidence** — `createForJob` guards double-booking three ways before the billable call: consignment_ref-present (:38-40), shipping-address presence (:43-45), and `canTransitionTo(Shipped)` before the courier call (:50-52). The requested_tracking_number is deterministic per quote (`NinjaVanTrackingNumber::forQuote`, :54) giving TOCTOU remote-uniqueness. HttpNinjaVanClient re-auths+retries once on 401/403 (:42-45). On success it routes through `QueueService->advance(job, Shipped, consignmentRef, carrier)` (:69-74) so audit/broadcast/OrderMilestone stay consistent. AppServiceProvider fails closed if creds are set but base_url is still the sandbox host.
- **impact** — The outbound half of the sync is sound: a shipment is pushed exactly once, at SHIPPED, with correct correlation identifiers.
- **fix** — None to the outbound path. Note for the build: the inbound handler must resolve jobs by this same consignment_ref/requested_tracking_number; the create-order request already sends `reference.merchant_order_number = quote tracking_code` (HttpNinjaVanClient.php:82), a second correlation key if webhooks echo it.

---

## G3 B2B payment

### 1. [HIGH][payment-correctness] B2B invoice payment_state is stuck UNPAID forever — no manual reconciliation path exists
- **file:line** — `app/Services/QuoteService.php:859`
- **evidence** — `issueInvoice()` creates every B2B invoice with `PaymentState::Unpaid` (QuoteService.php:859). The only writer of `PaymentState::Paid` is `PaymentService::confirmPaid` (`app/Services/Payment/PaymentService.php:119`), reachable exclusively via `payNow()` — the B2C flow gated by `pay_now_cutoff.b2c_enabled` and feature-flagged off. No staff endpoint/controller/service transitions an invoice to PAID/PARTIAL/VOID for B2B: `QuoteController` only reads payment_state into JSON (QuoteController.php:284); routes/api.php has no reconcile/mark-paid route; the staff UI (`frontend/src/pages/QuoteDetailPage.tsx`) doesn't reference payment_state. The migration comment (2026_07_01_000013:11) states "payment_state is reconciled manually by staff" — but that capability is not implemented.
- **impact** — The entire B2B manual-reconciliation model cannot be exercised. Every B2B invoice stays UNPAID permanently; PARTIAL and VOID are unreachable. Finance/AR has no system-of-record for payment status, and any downstream logic keyed off payment_state (dunning, reporting, release gating) can never see a paid order.
- **fix** — Add a staff-only endpoint/service method to update payment_state (UNPAID→PARTIAL/PAID/VOID) with actor + timestamp + audit log, mirroring the AuditLogger "payment.captured" pattern used by confirmPaid. Restrict to `permission:quotes.edit` and surface it in the staff invoice UI.

### 2. [MEDIUM][data-integrity] issueInvoice takes no row lock and no existing-invoice guard — concurrent submit can create two invoices for one quote
- **file:line** — `app/Services/QuoteService.php:853`
- **evidence** — `issueInvoice()` opens a `DB::transaction` but never `lockForUpdate()`s the quote and never checks for an existing invoice before `Invoice::create` (QuoteService.php:853-864). `transitionTo()` validates against in-memory `$this->state` (Quote.php:259), not a locked DB read. Two concurrent requests (separate route-model-bound instances) can both observe PROOF_APPROVED, both create an Invoice with distinct po_refs (so the unique po_ref constraint does not catch them), and both run transitionTo(Invoiced)→transitionTo(Confirmed). The sibling B2C `PaymentService::confirmPaid` deliberately locks and checks `purchaseOrders()->first()` for exactly this TOCTOU (PaymentService.php:76-84); the B2B path omits both. (A sequential double-click is safe — the second transitionTo(Invoiced) from CONFIRMED throws and rolls back — but surfaces a raw InvalidStateTransitionException.)
- **impact** — A genuine concurrent double-submit leaves two Invoice rows for one quote. Later re-anchoring (`amend()` :472, `retotalAfterReconfirm()` :1095) uses `purchaseOrders()->latest('issued_at')->first()`, so only one invoice stays in sync; the stale duplicate keeps a possibly-wrong amount and its own UNPAID state, corrupting AR/PO integrity.
- **fix** — Lock the quote (`Quote::whereKey(...)->lockForUpdate()->firstOrFail()`) at the top of the issueInvoice transaction and short-circuit if `$quote->purchaseOrders()->exists()`, returning the existing invoice — mirroring confirmPaid's idempotent guard. *(confidence: likely)*

---

## Inbound sync build spec

The auditors specified the exact NinjaVan-status → JobState → tracking-stage mapping below (from the "no representation for intermediate statuses" and "no app state for Failed/RTS/Cancelled" findings). Note that JobState has **no** transit states — every non-terminal courier event keeps `JobState = SHIPPED` and is expressed only through a new `last_courier_status` field surfaced per-shipment; only terminal delivery moves JobState to CLOSED. Inbound sync must act only on jobs already at SHIPPED (consignment_ref set) and must never regress internal production state.

| NinjaVan status | JobState | Tracking stage / label | Notes |
|---|---|---|---|
| Pending Pickup | SHIPPED | "Shipped" | Booked, not yet collected |
| Picked Up / Van en-route (to collect) | SHIPPED | "Picked up" | New sub-status label |
| In Transit / On Vehicle for Delivery / Transferred / Arrived at hub | SHIPPED | "In transit" | New sub-status label |
| Out for Delivery | SHIPPED | "Out for delivery" | New sub-status label |
| Completed / Delivered | **CLOSED** | "Delivered" | Terminal → `QueueService->advance($job, JobState::Closed)`; drives quote-close + OrderMilestone + broadcast |
| First / Second Attempt Failed | SHIPPED | "Delivery attempt failed" (flag) | Keep SHIPPED, set `last_courier_status` flag, optionally notify staff |
| Returned to Sender | **no JobState equivalent — design decision** | needs explicit outcome | New terminal JobState (e.g. RETURNED) or route to quote cancel/exception flow |
| Cancelled (courier-side) | **no JobState equivalent — design decision** | reconcile | Reconcile against `QuoteState::Cancelled` |

Correlation key for every inbound event: `consignment_ref` == `requested_tracking_number` == `NinjaVanTrackingNumber::forQuote(quote.id)` (ShipmentService.php:54). Secondary key if webhooks echo it: `reference.merchant_order_number` = quote tracking_code (HttpNinjaVanClient.php:82). **Blocker:** see G2 finding #4 — if NinjaVan prefixes the stored number, this key is wrong and all matching fails; resolve that first.

Required supporting changes:
- **Contract:** extend `CourierClient` with a status-fetch method, implemented on both `HttpNinjaVanClient` and `FixtureNinjaVanClient` (needed for the poll fallback and for tests).
- **Schema:** add `last_courier_status` (string, nullable), `last_courier_status_at`, `delivered_at` to `production_jobs`; optionally a `RETURNED` JobState (updates `nextStates()` and every `transitionTo` consumer).
- **Ingress:** unauthenticated, HMAC/signature-verified `POST /api/ninjavan/webhook` with its own throttle limiter (model on Stripe, routes/api.php:105); add `webhook_secret` to the ninjavan config block and fail closed. Idempotency via `canTransitionTo(Closed)` against duplicate deliveries.
- **Fallback:** scheduled `courier:sync-shipments` command over SHIPPED jobs with consignment_ref, `onOneServer()` + `withoutOverlapping()`.
- **Display:** surface `last_courier_status` per-shipment in `OrderTracker::shipments()` and broadcast it live (ties into G1 finding #1).
- **Override:** retain a manual staff advance for cases NinjaVan never reports terminal delivery.

---

## Top actions

1. **Fix the correlation key first (G2 #4, HIGH).** Persist NinjaVan's authoritative response `tracking_number` as `consignment_ref`, resolving the code-vs-comment prefix contradiction. Everything inbound and the buyer's tracking link depend on this single key; build nothing else until it is confirmed on a live sandbox.
2. **Build the inbound path (G2 #1/#2/#3, CRITICAL).** Add the signature-verified NinjaVan webhook + config secret (#1, #9), extend the CourierClient contract with a status fetch, and make terminal NV delivery — not a staff click — drive SHIPPED→CLOSED (#3). Add the scheduled poll as fallback (#2).
3. **Add courier sub-status storage and display (G2 #5/#6, HIGH + G1 #4).** New `production_jobs` columns and a documented decision for Delivery Failed / Returned-to-Sender / Cancelled; surface per-shipment status in OrderTracker and broadcast it live.
4. **Fix the live tracking merge (G1 #1, HIGH).** Add `shipments` + `items_completed`/`items_total` to `OrderTrackingUpdated::broadcastWith()` and the TrackPage listener so the tracking link appears without a reload.
5. **Implement B2B payment reconciliation (G3 #1, HIGH).** Staff-only endpoint to set payment_state with actor + audit log — the manual-reconciliation model the migration promises but never delivered.
6. **Harden outbound booking (G2 #7/#8, MEDIUM).** Derive real parcel weight from line items, and make the SHIPPED transition recoverable when the DB fails after a successful remote booking.
7. **Lock issueInvoice (G3 #2, MEDIUM).** `lockForUpdate()` + existing-invoice guard to prevent concurrent double-submit creating duplicate invoices, mirroring confirmPaid.
8. **Clarity + polish (G1 #2/#3, MEDIUM/LOW).** Split the "In review" bucket into buyer-actionable stages; render `placed_at`. Then the LOW outbound items: persist/label_url, validate ship-to fields with a 422.