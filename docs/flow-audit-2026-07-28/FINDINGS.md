# Gift-Lab — Wrong-Flow Findings (compiled)

**Date:** 2026-07-28
**Method:** read from **current code only** (9 parallel domain sweeps), every claim cites `file:line`. Stale docs (`docs/ORDER_WORKFLOW.md` 2026-07-21 etc.) were **not** trusted — several of their "blockers" are already fixed in code and are excluded here.

> ⚠️ **DO NOT EXECUTE / DO NOT FIX.** This is a findings register only. Per owner instruction the current feature set is frozen as MVP until the production date. Nothing here is to be changed until explicitly told. Ranked worst-first so triage is ready when the freeze lifts.

Legend — **Sev**: 🔴 High (money / security / dead-end) · 🟠 Med (wrong behaviour, workaround exists) · 🟡 Low (cosmetic / edge / defence-in-depth).

---

## 🔴 HIGH

| # | Area | Finding | Where | Why wrong / impact |
|---|------|---------|-------|--------------------|
| H1 | Payment | Delegated user-manager can reset a **superadmin's** password → account takeover | `AdminUserController::resetPassword` (`app/Http/Controllers/AdminUserController.php:271-289`; route `users.manage` `routes/api.php:313`) | Unlike `update`/`deactivate` (which block superadmin escalation + last-superadmin), `resetPassword` checks only `isStaff()` — no target-role guard. A `staff_admin` granted `users.manage` resets a superadmin's password and logs in as them. Full privilege escalation. |
| H2 | Catalogue | Publish gate **and** `products.approve` bypassed via the generic product PATCH | `AdminProductController::update` (`:751`, `:791-792`); route only `products.edit` `routes/api.php:255` | `update` accepts `publish_state ∈ {PENDING,PUBLISHED}` and blindly `fill()->save()` with **no** completeness/licence re-gate. A `products.edit` staff_admin (no `products.approve`) can push a `CANNOT_PUBLISH` blank (missing price/dims) or high-risk-licence 3D model straight to `PUBLISHED` → live storefront. `AdminCatalogueController::publish` enforces the gate; this path skips it. |
| H3 | Payment | Credit note **over-refunds** a PARTIAL invoice on cancel | `QuoteService::voidInvoiceAndCredit` (`app/Services/QuoteService.php:1134-1144`) | A PARTIAL invoice mints a `CreditNote` for the **full** `invoice->amount`, but only part was collected. No `amount_paid` column exists (`reconcilePayment` stores only an enum label + free-text note), so the system credits back more than was ever received. |
| H4 | Payment | B2B buyer is told **"Payment received"** while the invoice is UNPAID | copy `frontend/src/pages/QuoteDetailPage.tsx:36` vs `QuoteService::issueInvoice:1008` (`payment_state = UNPAID`) | On the B2B path invoicing precedes any payment. The INVOICED buyer status note asserts "Payment received and an invoice has been issued." — false for **every** manual-reconciliation order. |
| H5 | Quote | An **ACCEPTED plain-stock order (no artwork lines) is a dead-end** | `QuoteState.php:48` (only non-cancel exit = Proofing); `QuoteService::sendProofs:823` throws "Nothing staged"; `Quote::recomputeProofState:331-333` no-ops when no proof lines | A non-customised order the buyer has accepted can never be invoiced/produced — only cancelled. UI shows "No customised lines on this order to proof." with **no forward control** (`QuoteDetailPage.tsx:701-707`). |
| H6 | Proof | Dropping/amending a line **never rolls up** — order sticks in PROOFING/ARTWORK_APPROVED | `Quote::recomputeProofState` called only from the 3 proof-decision paths (`QuoteService.php:904,937,969`); `amend()` (`:418-435`) and reconfirm-drop (`:1302-1314`) don't call it | Docblock claims it runs "on every line drop / amend touching an artwork line" (`Quote.php:317-320`) — it doesn't. Drop the last unresolved artwork line after its siblings are approved → no further proof event can fire (approved proofs immutable) → quote never reaches approval rollup. Stuck. |

---

## 🟠 MEDIUM

| # | Area | Finding | Where | Why wrong / impact |
|---|------|---------|-------|--------------------|
| M1 | Payment | Pay-now button not gated **B2C vs B2B** — a B2B order can be Stripe-charged, bypassing manual reconciliation | `QuoteResource.php:63-64` (`pay_now_enabled` = global `config.pay_now_cutoff.b2c_enabled`); `PaymentService::payNow:41-48` | Neither the resource nor `payNow` conditions on the quote/company being B2C. With the flag on, any `PROOF_APPROVED` quote (incl. a B2B account order) shows "Pay now" and can be charged. |
| M2 | Procurement | **Accept-as-is** re-totals with bare `lineTotal()`, not the fee-inclusive contribution | `QuoteService.php:1291,1299` (approve) vs `:1271`/`:1310` (amend/drop use `lineSubtotalContribution`) | A customized `qty_short` line accepted as-is keeps charging the per-unit decoration fee for units never produced. Buyer over-charged; the money doesn't follow the goods. |
| M3 | Procurement | **3D filament** consumed by a direct column write — never returned on cancel, never restocked on receive | `Model3dProcurement.php:64-65` (direct `filament->qty_on_hand` save, no ledger); `returnConsumedStock` variant-only (`:1174-1195`); `AdminReorderController::receive` restocks variant-only (`:74-83`) | Filament has no `StockMovement` analogue. Cancelling a 3D order **permanently loses** the filament from inventory; a filament reorder marked received flips state but adds **zero** grams back. CORE stock is handled correctly. |
| M4 | Quote | `INVOICED` is a pass-through state no order rests in, yet the buyer UI treats it as a restable "Pay invoice" state | atomic `Invoiced→Confirmed` (`QuoteService.php:1021-1022`); dead UI at `QuoteController::summary:114`, `BuyerDashboardPage.tsx:15`, `OrderStatus.tsx:24`, `QuoteDetailPage.tsx:36` | Four UI paths reference a state that never renders — the "Pay invoice" affordance is unreachable dead code and inflates the "awaiting" bucket falsely. |
| M5 | Quote | Dashboard **"Awaiting you"** omits ARTWORK_APPROVED and PROOF_APPROVED | `QuoteController::summary:114,120-124` (bucket only SENT/PROOFING/INVOICED) | A buyer who must accept the price (ARTWORK_APPROVED) or pay (PROOF_APPROVED) is never listed as "waiting on you." Combined with M4 (INVOICED never occurs), the list can only ever surface SENT + PROOFING. |
| M6 | Quote | `CHANGES_REQUESTED → DRAFT` edge has **no caller** (dead edge) | enum advertises it `QuoteState.php:47`; no `transitionTo(Draft)` exists in `app/` | Staff cannot pull a changes-requested order back to DRAFT to re-price; only re-proof or cancel. Documented recovery path unimplemented. |
| M7 | Quote | The **artwork-first (slim) path is unreachable from the DRAFT UI** | `sendProofs` Draft branch (`QuoteService.php:833-841`) needs sending while `accepted_at` null, but the DRAFT panel is staging-only (`QuoteDetailPage.tsx:665-674`) | ARTWORK_APPROVED, its dedicated milestone email, and the DRAFT→Proofing edge are reachable only by calling `POST /quotes/{q}/proofs/send` directly on a DRAFT. |
| M8 | Buyer | **"Upload finished look"** is live despite a comment saying it's gated off — mispricing | `frontend/src/pages/ProductDesignerPage.tsx:80` (`FINISHED_LOOK_ENABLED = true`) vs comment `:76-79` | `mode='buyer_uploaded'` is fully reachable; such lines carry no `logo_size`, so they price with the flat customization fee only (or as a blank) — the exact un-built pricing path the comment says to avoid. |
| M9 | Buyer | Text-personalization fee appears on the **final quote** but never in the pre-submit **estimate** | `cartStore.ts:6-8,73-81` & `ProductDesignerPage.tsx:166-176` omit `has_text`; `QuoteService.php:191-192` charges `customization_per_unit` | A line with text quotes higher than the estimate the buyer was shown. Client-side omission only (`PriceEstimateRequest.php:34` accepts `has_text`). |
| M10 | Buyer | One unavailable line **poisons the whole cart estimate + checkout**, with no per-line signal | `PriceEstimateController.php:56-58` (batch 422); `CartPage.tsx:154-155` full ErrorState | A persisted cart holding a since-unpublished product is a soft dead-end — the buyer can't tell which item to remove. |
| M11 | Proof | Rollup transition guard **fails silently**, stranding valid states | `Quote.php:374-376` (`if (!canTransitionTo) return;`) | A superadmin amend that reopens an artwork line computes target=PROOFING, hits the guard (no edge back from ARTWORK/PROOF_APPROVED), no-ops with no error or log. Order & proof states silently diverge. |
| M12 | Proof | Buyer sees **unsent DRAFT proofs** and raw internal states | `QuoteController::show:176` eager-loads all proofs unfiltered; `ProofResource.php:47` emits raw state; `QuoteDetailPage.tsx:1180,455` | Staged-but-unsent artwork and the internal "Draft" label leak to the buyer portal before staff formally send. |
| M13 | Proof | Staff **resend emails the wrong line's artwork** | `resendProof` → `emailQuoteReady` picks `quote->proofs()->latest('version')` (`:1507`); versions are per-line (`:799`) | On a multi-line order the resent email shows the highest-version line's image, not the one being resent. |
| M14 | Courier | **Suffix-match** job lookup is ambiguous + unordered → an event can hit the WRONG job | `NinjaVanWebhookController.php:228-233` (`str_ends_with` over unordered `->first()`) | If two SHIPPED jobs' refs are trailing substrings (e.g. `GL1` vs `XGL1`), the wrong order can be marked delivered/returned. Exact-match path is safe; only the recovery fallback is at risk. |
| M15 | Courier | `cancel_credit` cancels the **entire quote** for a single returned parcel on a multi-job order | `QueueService.php:544-553` → `QuoteService::cancel:1084-1102` | Voids the whole invoice + credits the full amount + returns all stock, even if sibling jobs were already delivered/closed. No per-job scoping. |
| M16 | Notif | `reminders_sent` is **shared across both chase ladders** and never reset | `ChaseUnansweredOrders.php:105,124` (only ever incremented) | A quote chased on price in SENT (e.g. `sent=2`), then advanced to PROOFING, enters the proof branch at rung index 2 — skipping early proof reminders or instantly "exhausted." |
| M17 | Notif | Disabling a reminder still **burns ladder rungs** and flags "exhausted" without sending | `ChaseUnansweredOrders.php:120-134` (increments/logs unconditionally after a no-op `send()`) | Turning `reminder_price`/`reminder_proof` off means the buyer is emailed nothing, yet the order still marches to "flagged for staff, no further emails." Setting gates the email, not the escalation. |
| M18 | Notif | `OrderMilestone::ProofIssued` enum copy **never renders** — the proof email is a different mailable | `emailProofsReady` queues `QuoteReadyMail`, not `OrderMilestoneMail` (`QuoteService.php:856-874`); `OrderNotifier::send` never called with `ProofIssued` | `ProofIssued`'s subject/heading/body/cta (`OrderMilestone.php:39,61,79,98`) are dead code; enum claims diverge from reality. |
| M19 | Notif | **Multi-job orders send multiple "Shipped" emails** | `QueueService.php:285-319` (fires per `ProductionJob`) | A parcel-split order → one "on its way" email per job, not per order. Every other milestone is order-level; this one is job-level. |
| M20 | Admin | Blank-recommender ingest + public gift-ideas writes have **no granular permission gate** | `AdminBlankRecommendationController::add/feature/unfeature` `isStaff()`-only (`:71-167`); no `permission:` middleware `routes/api.php:274-278` | `add` runs the same scraped ingest that capture restricts to `products.edit`; `feature/unfeature` mutate **public** content. Any staff_admin — even one whose allowlist excludes `products.*` — can seed catalogue rows + change the public page. |
| M21 | Payment | `PARTIAL` payment carries **no financial data** | `QuoteService::reconcilePayment:1064`; `ReconcilePaymentRequest.php:32`; reanchor `:605-606` | Reconciling to PARTIAL only flips an enum — no partial amount stored anywhere. "Partial" is unquantified (feeds H3). |
| M22 | Notif | **Reorder silently drops a deleted variant** instead of skipping/flagging the line | `QuoteService.php:122-129` clones `variant_id` blindly; `createFresh:173` resolves null | Only *products* are pre-filtered. A deleted variant → line still created, variant-less, re-priced off the base product. Silent price/spec change the buyer isn't told about. |

---

## 🟡 LOW (edge / cosmetic / defence-in-depth)

| # | Area | Finding | Where |
|---|------|---------|-------|
| L1 | Quote | Buyers see internal state jargon ("Invoiced", "Procuring", "Proof approved") | `OrderStatus.tsx:114-116` humanizes raw state; honest `trackingStage` used only on public tracker |
| L2 | Quote | `accept()` has no explicit state guard; route has no permission gate (safe by txn rollback, but staff can fire buyer-accept, raw 422 on misuse) | `QuoteService.php:735-746`; `routes/api.php:146` |
| L3 | Buyer | Post-session-expiry redirect drops the return path → buyer lands on `/account` not `/checkout` | `frontend/src/lib/api.ts:44` (`assign('/login')` no `from`) |
| L4 | Buyer | Stale `needed_by` in a persisted cart 422s at checkout, surfaced only as a flattened banner | `StoreQuoteRequest.php:55`; `cartStore.ts:19-21` |
| L5 | Buyer | No join-existing-company path — every registration mints a new tenant, fragmenting buyer data | `AuthController.php:52` (by spec, flagged as structural gap) |
| L6 | Buyer | Buyer-uploaded line with only reference images priced as a blank (no decoration fee) | `QuoteService.php:1556-1565` (compounds M8) |
| L7 | Payment | Stripe webhook: no event-level idempotency store; a rethrown non-unique QueryException 500s (Stripe retries) | `StripeWebhookController.php:41-47`; `PaymentService.php:110-116` |
| L8 | Payment | Unconfigured webhook secret → 400 → infinite Stripe retry storm | `StripeWebhookController.php:24-27` |
| L9 | Payment | Checkout hides the GST line but keeps GST in the charged total when delivery is "unreliable" — shown number understates real total | `CheckoutPage.tsx:391-404` (intentional per comment) |
| L10 | Courier | Webhook has no event-level idempotency: duplicate returned/failed events re-fire staff alerts + re-broadcast | `NinjaVanWebhookController.php:140,202-204` |
| L11 | Courier | No replay protection on webhook signature (HMAC over body only, no timestamp/nonce) | `NinjaVanWebhookController.php:236-241` |
| L12 | Courier | `cancel_credit` leaves the SHIPPED job in the Awaiting-Delivery list, re-resolvable on an already-cancelled quote | `QueueService.php:544-565`, `465-473` |
| L13 | Courier | Reship allows a second "Shipped" email; the "one send per job" invariant comment no longer holds | `QueueService.php:294` vs `510-542` |
| L14 | Courier | Returned parcel (still SHIPPED) shows as a "completed/shipped" item in the buyer tracker | `OrderTracker.php:70-78`; `TrackResultView.tsx:64-70` |
| L15 | Courier | Dispatch/pickup date computed in app TZ, not the timeslot's Asia/Singapore TZ (off-by-one masked by +1-day margin) | `CourierConfig.php:116-119,181-183` |
| L16 | Courier | Manual `markDelivered` lacks the webhook's row lock (TOCTOU with a racing delivered webhook → possible double email) | `QueueService.php:417-454` |
| L17 | Courier | Signed track link is permanent + unrevocable (`expiration = null`); leaked QR exposes PII-free status forever | `OrderTracker.php:52-59`; `routes/api.php:102-104` |
| L18 | Proof | Revised-proof round can be silently email-less if the buyer muted ProofIssued; must review in-portal to progress | `QuoteService.php:858,862-863`; inconsistent with resend's `emailQuoteReady` (ungated) |
| L19 | Proof | Blank/whitespace change-note passes server validation on direct API calls (UI blocks it) | `DecideProofRequest.php:38`; `QuoteService.php:925-927` |
| L20 | Proof | "Is this an artwork line?" is defined three ways; buyer side uses bare truthy `li.customization` | `LineItem.php:125-148` vs `QuoteDetailPage.tsx:265-270,323` |
| L21 | Notif | Reorder re-prices with no availability/publish check — buyer can reorder an unpublished product | `QuoteService.php:109-140` |
| L22 | Notif | Reorder route has no rate/permission middleware + no idempotency key → rapid clicks mint duplicate drafts | `routes/api.php:147-151`; `QuoteService.php:139` |
| L23 | Production | Create-shipment button disabled on first load even when a valid address exists (readiness lives only in ephemeral UI) | `frontend/src/pages/ProductionQueuePage.tsx:446-449` |
| L24 | Production | `production-file` download button label promises `.3mf` from a serialized ref, not from what the endpoint returns (stale TODO comment) | `ProductionQueuePage.tsx:752-756,878` |
| L25 | Admin | CORE create can self-publish, skipping `products.approve` (data is present, but the gate is bypassed) | `AdminProductController::store` (`PRODUCT_RULES:76`, applied `:417`); route `products.edit` |
| L26 | Admin | CSV import route middleware (`products.edit`) understates the real requirement (controller = superadmin) → confusing 403 | `routes/api.php:251` vs `AdminProductController::import:451` |
| L27 | Admin | Auto-publish route has no `permission:` middleware — relies solely on an in-controller superadmin check (no defence-in-depth) | `routes/api.php:241`; `AdminCatalogueController::setAutoPublish:785` |
| L28 | Admin | Frontend `hasPermission` grandfathers *missing* perms to `true` for every key incl. sensitive; backend grandfathers `null`→`defaults()` (excludes pricing/users) | `frontend/src/lib/roles.ts:30` vs `User.php:105,124-126` (mitigated: `/me` always emits effective perms) |

---

## Design notes (verified correct — NOT defects, listed so they aren't re-flagged)

- **READY→Cancelled asymmetry is intentional** — the enum edge exists only for `resolveReturnCancelCredit`; general `POST /cancel` refuses READY (`QuoteController.php:359-363`). `QuoteState.php:65`.
- **Download no longer auto-advances** a print job to in-production — the old foot-gun was removed and is regression-tested (`AutoAdvanceOnDownloadTest.php`).
- **`ProcurementPage` now loads its own data** (initial `GET /procurement/awaiting-reconfirm` + live subscribe) — the old broadcast-only defect is fixed (`procurementStore.ts:89-113`).
- **All-lines-dropped no longer dead-ends** — `cancelIfNothingLeftToProduce` auto-cancels via `tryQueue` (`QuoteService.php:1434-1457`).
- **GST rounding is consistent** (single boundary rounding, snapshot-rate reuse on amend, invoice copies quote figures) — no rounding drift.
- **Default `QTY_SHORT` is advisory, not blocking** — only `PRICE_JUMPED` (SCRAPED_UV) blocks by default; `confirm-stock` is the human backstop.
- **`/track` enumeration is well-defended** (generic 404 + `hash_equals` + 10/min throttle).

## Not implemented (absent from shipped code, if expected)

- **Monetary high-value / high-risk-order manual-review gate** — no threshold anywhere in the payment/invoice path. The only "high_risk" concept is the unrelated 3D-model licence tier (`License.php:58-65`). A GST+high-value plan exists under `docs/` but is not in code.

---

## Live-test findings (browser, not from code-reading)

Surfaced by driving the running app. These are **new** (the code sweeps didn't catch them) or confirm a code finding in the UI.

| # | Sev | Finding | Evidence |
|---|-----|---------|----------|
| LT1 | 🔴 High | **Most of the storefront can't be ordered.** 3 of 4 published products (Baseball Cap, Ceramic Mug, Tote Bag) have **zero variants** → browsable, customizable, add-to-cart-able, but `POST /quotes` 422s **"This product cannot be ordered yet — no variants are configured"** only at the final Place-order click. Only the enamel pin (1 variant) is orderable. | Verified: variant counts 0/0/0/1; live 422 then 201 on the pin. |
| LT2 | 🟠 Med | **Publish gate doesn't require a sellable variant.** A CORE product with no variant passes publish + reaches storefront + shows a price, but is un-orderable. The completeness gate checks price/dims/printable/stock, not "has ≥1 variant." | Root cause of LT1; ties to H2/L25. |
| LT3 | 🟠 Med | **"Out of stock" really means "no variant / not set up to sell."** Every variant-less product shows *Out of stock* (home + PDP, twice) yet still offers Add to cart + Customize — a made-to-order shop shouldn't show stock scares, and here it's masking an un-sellable product. | PDP + home text. |
| LT4 | 🟠 Med | **Stale login on checkout.** Immediately after signing in and returning to checkout, it still showed *"Sign in to place your order"* until a manual reload; then the address form + Place order appeared. A real buyer reads that as "login failed." | Reproduced live. |
| LT5 | 🟡 Low | **"Company default" address option is misleading when no default exists** — selecting it neither autofills nor warns; the buyer must still type everything. | Proof Demo Co has no saved default. |
| LT6 | 🟡 Low | **Footer links are dead / inconsistent.** "About" and "Help" both point to the current page (`/checkout`, `/products/...`); the footer still shows "Log in" while the user is logged in. | Interactive tree. |
| LT7 | 🟡 Low | **Fake seeded reviews on every product** ("4.8 ★ (128 reviews)", Priya S./Marcus L./Wei Tan). Trust/legal risk at launch. | PDP text. |
| LT8 | 🟡 Low | **Duplicate-key render warning on the product page** (host-independent) — can duplicate/omit list items. | Console, `ProductDetailPage.tsx:87`. |
| LT9 | 🟡 Low | **Raw "CSRF token mismatch." leaks to buyers** when the page host and API host don't match exactly. Not triggered on the correct host, but a production domain misconfig would show buyers this developer text instead of a friendly error. | Repro via `127.0.0.1` vs `localhost`. |

**Not a bug (tester artifact, logged for honesty):** the initial "pricing dead / login broken" alarms were caused by opening the app at `127.0.0.1:5173` while the API base is `localhost:8000` (the security cookie can't cross hostnames). On the correct host (`localhost:5173`) pricing, volume tiers, login, and checkout all work. **Verify the production site domain and API domain line up before go-live** (see LT9).

**WF-01 verdict:** buyer journey works end-to-end **for an orderable product** — browse → customize → cart → checkout → place order → confirmation modal with order ref + tracking/QR. Blocked for 3/4 of the catalogue by LT1. Throwaway order `WWZDVWAKGF` created for downstream workflow tests.

### WF-03 live findings (quote → proof → commit)

| # | Sev | Finding | Evidence |
|---|-----|---------|----------|
| LT10 | 🔴 High | **The order-detail page hangs & renders blank for any order that has proofs.** `ProofResource.php:44-46` calls `ProofCompositeService::signedCompositeUrl` for **every proof on every page load**; uncached, `render()` reads the artwork then HTTP-fetches the product image with a **10s timeout** (`ProofCompositeService.php:185`) and composites via GD. N proofs = N synchronous fetches/composites **before the page shows anything**, with **no error** on failure — just a blank page. Production-relevant even with storage reachable (first load of a multi-proof order does N round-trips inline). Confirmed: seeded PROOFING order `AVYBQQVZCX` (4 proofs) → `GET /api/quotes/AVYBQQVZCX` times out 20s+, page blank. | Live 20s timeout; code path. |
| LT11 | 🟠 Med | **Milestone/quote emails send via synchronous SMTP; a slow/unreachable mail host freezes the single-threaded request.** Clicking "Send to buyer" against real Gmail SMTP (`.env`) hung the dev server so **every later request** (incl. `/api/user`) stalled → app-wide "Checking your session…" freeze. Email in the request's critical path should be queued, not inline. | Reproduced live; `/api/user` 15s timeout. |
| LT12 | 🟠 Med | **`.env` holds live secrets** — real DigitalOcean Spaces access key + secret, and a real Gmail account + app password, in plaintext. Rotate + move to a secret store before this environment is shared or committed. | `.env:56-…`. |
| H5-confirmed | 🔴 High | **Plain-stock order dead-ends at Accepted (H5) — reproduced live.** Throwaway order `WWZDVWAKGF` (plain enamel-pin line, no customization): Draft→Send→buyer Accept→**Accepted with only "Cancel order"**; proof panel says *"No customised lines on this order to proof"* with no way to proof and no forward step. Buyer was told *"we're preparing your first proof"* — which can never come. | Live walkthrough. |

**Other WF-03 UX notes:** line total (30×6.00 = SGD 180) vs subtotal (SGD 205) shows an **unexplained SGD 25 gap** with no line item for it (staff + buyer views); buyer sees **"step 2 of 9 / 3 of 9"** exposing the internal state machine; the Sent-state "Buyer notifications" panel reads *"No buyer email is sent at this step"* even though Send just emailed the buyer.

**Test-environment changes made to proceed (revertible, NOT product changes):** `.env` → `MAIL_MAILER=log` (stop real-email sends + SMTP hang), `FILESYSTEM_DISK=local`, `ARTWORK_DISK=local` (avoid unreachable Spaces). Product image_url for cap/mug/tote repointed to a local static server on `:8001` so the proof composite's image fetch doesn't self-call the single-threaded API. API run with a concurrent-ish setup. A fixture order (`CZJ5J37HFT`, customized pin line + local artwork) was built via the app's own services to drive the proof→production chain.

**LT10 correction:** with the product image served from a separate process, the proof order loads in **~0.9s** (was 20s+). So the catastrophic *hang* was the single-threaded dev server calling itself (Windows can't fork workers) — a dev-env artifact, not a production hang. The residual real concern is milder: **the composite is generated synchronously during page serialization** (first-load latency + an in-path external image fetch with a 10s timeout). Downgrade LT10 to 🟠 Med: move composite generation off the synchronous page-load path (cache/async).

### WF-03/04/05/07 live findings (full lifecycle drive on fixture `CZJ5J37HFT`)

**The whole staff spine works end-to-end and the UX is genuinely strong** — every state has a clear next-step explanation, irreversible actions (Commit, Mark delivered) get confirm dialogs, the buyer-notification panel shows exactly which email fired + when, reminder ladders are surfaced, and the consignment number links out to the carrier's tracker. Confirmed live: Proof approved → Commit → Confirmed → Run procurement → Confirm stock → Ready → Start production → Mark shipped → Mark delivered → **Closed**.

| # | Sev | Finding | Evidence |
|---|-----|---------|----------|
| M4-confirmed | 🟠 Med | **INVOICED is invisible — confirmed live.** Committing jumped the step counter **5 → 7** (Proof approved → Confirmed); step 6 (Invoiced) never renders. | Live. |
| LT13 | 🟠 Med | **After shipping, the staff order page gives no shipment visibility and no delivery action.** Order stays "Ready / No staff action available" with no shipped badge, no consignment, no "Mark delivered" — staff must know to go to the Production → Awaiting-delivery panel. | Live: shipped job, order page unchanged. |
| LT14 | 🟠 Med | **An order can be Closed/delivered while its invoice is still UNPAID, with no flag.** `CZJ5J37HFT` reached Closed with invoice "Unpaid" and no reminder/warning that a completed order was never paid. (May be intended for B2B terms, but there's zero visibility.) | Live. |
| LT15 | 🟡 Low | **Line status stays "Ready" on a Closed order** — the line-item state never advances to a delivered/closed equivalent, so a Closed order shows a "Ready" line. | Live. |
| LT16 | 🟡 Low | **Customization fee is baked into subtotal with no line for it.** Every customized order shows `line total (qty×unit) ≠ subtotal` with an unexplained gap (pin: 225 vs 258; +33 fee). Staff and buyers both see numbers that don't add up. | Live, multiple orders. |
| LT17 | 🟡 Low | **Awaiting-delivery panel doesn't appear until a manual reload** after shipping (realtime didn't mount it immediately). | Live. |
| L23-confirmed | 🟡 Low | **Create-shipment button shows "Open delivery address to confirm before booking"** even when ready — must expand the address panel first. | Live. |

**Env caveat:** NinjaVan credentials are set, so "Create NinjaVan shipment" calls the real courier API (unreachable here) — used manual "Mark shipped" instead. Same finding class as LT10/LT11: external calls (courier, storage, mail) sit in synchronous request paths.

---

## Fix log

**Batch 1 — 2026-07-28 (no-hanging MVP):** H1, H2, H5, H6, M5/M6, LT1 (P1 guard), LT13, P2, H4. Shipped to `master`.

**Batch 2 — 2026-07-29 (pre-production code fixes):**
- **M2** — accept-as-is now re-totals fee-inclusively (no decoration charge on un-made units).
- **M3** — 3D filament is returned on cancel and restocked on filament-reorder receive (new `line_items.consumed_grams`).
- **LT7** — fake product reviews + rating removed.
- **LT6** — dead footer About/Help links removed; footer Account links reflect sign-in state.
- **LT10** — proof composite no longer generated on the synchronous page path (cached-only lookup; generation stays on the queued email path). Note: the dramatic *hang* was a single-threaded dev-server self-call artifact; production needs a multi-worker server + reachable storage regardless.
- **LT11 — corrected, not a bug.** All mailables already implement `ShouldQueue` and send via `->queue()`, so emails are already async. The hang observed when clicking "Send" was LT10's composite self-call on the next page load, not a synchronous email send.

**Batch 3 — 2026-07-29 (Med findings sweep):** M22, M20, M12, M13, M16, M17, M11, M9, M19, M14, M10, **M8**. Each with a test.
- **M8** — upload-finished-look entry point disabled (`FINISHED_LOOK_ENABLED = false`): a finished-look photo has no logo size band, so its decoration fee (keyed on S/M/L) can't be derived and the line would misprice as a flat fee or blank. Component + backend stay in place for when the pricing is built.

**Remaining Med — owner decisions recorded 2026-07-29 (NOT code-fixed):**
- **M1** (pay-now not gated B2C vs B2B) — **DECISION: keep the global switch OFF for MVP, no code.** Confirmed `PricingConfigSeeder.php:56` seeds `pay_now_cutoff.b2c_enabled => false`, and all three read sites default to false — so card pay-now never renders in real data. Revisit when self-serve card payment is actually wanted; the real fix then needs a per-company "pay-now eligible" flag (no B2C/B2B marker exists today — `default_terms` is free-text NET30 for all).
- **M7** (artwork-first slim path unreachable from DRAFT UI) — **DECISION: leave as-is, defer.** Nobody's blocked; the built path stays dormant. Revisit post-MVP (add a "Send proofs now" DRAFT action, or delete the dead edge/email).
- **M15** (cancel_credit voids the whole order for one returned parcel on a multi-job order) — rides with **H3**: both need the partial-credit / partial-restock machinery. Deferred to post-production. Money-critical.
- Cosmetic: **M4** (INVOICED dead UI), **M18** (ProofIssued enum copy unused).

**Batch 4 — 2026-07-29 (partial-money cluster, quick-map bucket 1):** H3, M21, M15. Each with tests. *(Owner lifted the post-production freeze on this cluster.)*
- **H3 + M21** — invoices now record `amount_paid`; reconciling to PARTIAL requires the collected amount (> 0, < total), PAID stamps the full amount. Cancel credits `Invoice::collectedAmount()` (only what was received), never the full invoice. `balance_owed` exposed; staff page shows "X collected / Y owed" and a partial-amount input.
- **M15** — new terminal `JobState::Returned`; `QuoteService::returnParcel()` cancels & credits ONLY the returned parcel of a multi-parcel order (restocks its lines, reduces the invoice by the parcel's proportional share, credits only that share of the deposit — proportional, never > collected). Whole-order cancel only when it's the last live parcel. Owner decisions: proportional refund; add a real "Returned" parcel state.

**Batch 5 — 2026-07-29 (quick-map buckets 2–4).** Each with tests unless noted.
- **Bucket 2 — LT14:** delivered-but-unpaid orders now surface (dashboard "Delivered · unpaid" count + order-page "payment outstanding" banner).
- **Bucket 3 — LT16, M4, L1:** personalisation fee shown as its own line so totals reconcile; INVOICED dropped from the buyer step counter (no 5→7 skip); buyer dashboard uses plain-language status. *(M18 left — correctly-dead enum copy the toggle still needs.)*
- **Bucket 4 — hardening:**
  - **L25/L26/L27** — new `EnsureSuperadmin` middleware on auto-publish + CSV import; `store()` gates self-publish on `products.approve`.
  - **L28** — FE `hasPermission` no longer grandfathers sensitive Pricing/Users sections.
  - **L21/L22** — reorder skips no-longer-buyable products; route throttled against rapid-click duplicate drafts.
  - **L7/L10/L11** — `processed_webhook_events` idempotency/replay ledger for the Stripe + NinjaVan webhooks.
  - **L16** — `markDelivered` locks + re-reads under the transaction (TOCTOU with a racing delivered webhook).
  - **L15** — dispatch-date floor anchored to the courier (Asia/Singapore) timezone.
  - **L12** — a cancel-&-credit parcel now moves to the terminal RETURNED state (M15), so it drops off the awaiting-delivery board instead of lingering re-resolvable. *(Fixed as a side effect of M15.)*

**Reviewed, left by design (no code change):**
- **L8** — Stripe unconfigured-secret returns 400 (fail-closed). Returning 2xx to stop the retry storm would silently accept+drop real payment confirmations on a misconfigured deploy — worse than the storm, which is itself the signal to fix the config.
- **L17** — the signed track link is deliberately permanent + PII-free (name+qty only) so a buyer can bookmark it; adding an expiry would break that UX. True revocation would need tracking-code rotation (a feature, not a fix).
- **L5, L9** — structural/by-spec (single-tenant registration; GST-in-total when delivery is unreliable) — unchanged as noted in the register.

**Batch 6 — 2026-07-29 (cosmetic/edge Lows tail).** Each with tests unless noted.
- **L2** — `accept()` has an explicit state guard (clean domain error, no accepted_at stamp, instead of a raw state-machine 422).
- **L3** — a mid-session 401 carries the current path as `?from=` so login returns the buyer there (e.g. back to /checkout), validated same-origin.
- **L4** — a persisted cart's now-past "need it by" is cleared on checkout entry, so it no longer dead-ends at submit with a flat banner.
- **L13** — the stale "one Shipped email per job" comment corrected (a reship legitimately re-notifies; M19 dedupes the parcel-split case). *(Comment only.)*
- **L14** — a returned-flagged parcel still sitting SHIPPED no longer counts as a completed/shipped item on the buyer tracker.
- **L18** — a proof-ready email suppressed by a disabled milestone is now logged (not silently email-less). *(Log only.)*
- **L19** — a whitespace-only proof change-note is rejected on a direct API call (trim before validation), matching the UI.
- **L20** — `LineItemResource` exposes the authoritative `needs_proof` (LineItem::needsProof); the buyer/staff UI uses it instead of re-deriving from bare `customization`.
- **L23** — already fixed (create-shipment opens the confirm modal without needing the panel opened first).
- **L24** — the production-file download gates on `production_file_ref` alone, so it stays hidden until the (unbuilt) endpoint exists instead of 404ing on 3D products.

**Reviewed, left by design (no code change):** L5, L8, L9, L17 (see notes above/in the register).

**Batch 7 — 2026-07-31 (walkthrough F-findings + parked UX + tail).** Shipped as PRs #17–#20, each with tests; `tsc` + backend suites green.

- **PR #17 — transactional email correctness:**
  - **F7** — the "Accepted" milestone email promised "your artwork proof" even on a plain-stock order that skips proofing; `OrderMilestone::body()` now takes a proof-lines flag and says "getting your order ready for production" when there's nothing to proof.
  - **F5** — a plain (no-proof) quote-ready email fell through to an empty dashed "Proof preview" box; the proof block is now gated on a proof actually existing.
  - **F6** — "1 item(s), 50 unit(s)" → proper `Str::plural`.
- **PR #18 — buyer progress + delivered-unpaid:**
  - **F4** — the buyer order page leaked the internal state machine ("step N of 8", raw next-state, the who/when audit ledger); `OrderStatus` gains an `audience` prop and buyers now see a friendly four-stage progress (Quote → Proof → Production → Delivered) only. *(Subsumes the buyer half of L1.)*
  - **F9** — the dashboard "Delivered · unpaid" tile now links to `/quotes?filter=delivered_unpaid`; the index supports the filter (CLOSED + invoice UNPAID/PARTIAL) and shows the balance owed per row.
- **PR #19 — cosmetic copy tail:**
  - **F1** — register validation errors now render inline per field (422 field bag) instead of one lumped Laravel-default banner.
  - **F2** — the PDP volume tier reads "1 pc" (singular) for a qty-of-one tier.
  - **F3** — the cart estimate now itemises the personalisation/setup fee on its own line (as the order page does); `PricingService::quoteTotals` returns `customization_fee`.
- **PR #20 — delivered line state + UX tail:**
  - **LT15** — a delivered order no longer shows "Ready" line items: new terminal `LineItemState::DELIVERED` (migration extends the enum); `QueueService` advances a closed order's READY lines to DELIVERED.
  - **P4** — the buyer "Next step" action card is now `lg:sticky`, staying in view while scrolling the order detail.
  - **P5** — the production board's per-job facts (Track/Qty/Ready/Status) now align in a fixed grid so the board scans like a table (kept card-based so the rich per-job controls survive; not a semantic `<table>`).
  - **M18** — `OrderMilestone::ProofIssued` documented as a notification-preference key only (the proof email is the richer `QuoteReadyMail`); its unused generic copy no longer reads as a live-but-diverging mailable.

Already verified done before this batch (no new code): **F8** (Commit PO field required), **F10** (returned-parcel resolution UI), and parked **P1/P2/P3/P6/P7/P8** (orderability gate, buyer next-step card, buyer table view, NinjaVan confirm modal, tracker names×qty, reorder-rail thumbnails).

**Batch 8 — 2026-07-31 (staff list filters + post-audit refinements).** Shipped as PRs #21–#24, each with tests; `tsc` + full suites green (backend 1116, frontend 521).

- **PR #21 / #22 — staff-list filter system (owner request: "more complete filters than a search box — status, sort, etc.; popup that only hits the API on submit; active filters as removable badges").**
  - New shared `ListFilters` popup + removable `FilterBadges` (config-driven `FilterField[]`; applying or removing a badge is the only thing that fetches). Rolled to five staff menus: **Quotes** (status, payment, company, value, created/needed dates, sort), **Procurement** (search, updated range, sort), **Buy-list** (search, kind, state, negative-on-hand, created, sort), **Production** (track, print method, ready date — client-side board filter), **Users** (status/role/company refactored into the popup + joined range + sort). Search stays its own input; free-text `q` is defensively ignored if passed as an array.
  - Left as-is by decision: the bespoke **Products** / **Catalogue-gate** filter systems (already working, carry features — bulk-archive, sort-with-dir — the shared popup doesn't cover).
- **PR #23 — refinements #1 / #5 / #6:**
  - **#1** — the "Personalisation" fee row was hand-rolled in the cart estimate and the order-detail summary with divergent formatting (`toFixed(2)` vs a raw string); extracted one shared `<FeeLine>` (guards on `> 0`, formats to 2dp) used by both.
  - **#5** — the buyer progress stepper always showed a **Proof** stage, even for a plain-stock order that skips proofing (a step never reached); `QuoteResource` now exposes a `needs_proof` aggregate and `BuyerProgress` drops the Proof stage when false. *(Extends F4.)*
  - **#6** — the "renders its own mailable vs the generic `OrderMilestoneMail`" distinction was implicit across the state map and call sites; made explicit as `OrderMilestone::rendersOwnMailable()` (true only for `ProofIssued` → `QuoteReadyMail`), and `OrderNotifier::send()` now guards against routing an own-mailable milestone through the generic template. *(Builds on M18.)*
- **PR #24 — refinement #3:** the buyer's designer art was auto-staged as DRAFT proofs by a client-side page-load `useEffect`; moved the trigger server-side onto the accept choke point (`QuoteService::accept` → `autoStageDesignerProofs`), so staff open the proofing desk to the drafts already there. Removed the client `useEffect` + store action; kept the (still-tested, idempotent) endpoint as a manual re-stage.

**Owner-deferred (decision recorded, intentionally NOT built):** **M1 / #8** (pay-now B2C/B2B gating — one global switch, default OFF; per-company flag and pay-now eligibility left until card payments are enabled), a **high-value/high-risk manual-review payment hold** (never specced into code), and refinement **#7** (Shopee affiliate-link staff stock-check — owner opted to use affiliate links via a non-affiliate buyer account; the self-referral risk is knowingly accepted, not mitigated in code).

**Declined by decision (not a defect):** refinement **#4** (production queue → real `<table>`) — the board deliberately keeps card+grid rows because each job carries rich controls (expandable customization, per-artwork downloads, ship-address + mark-shipped forms, split-shipment) that don't fit `<td>`/`<tr>`; the aligned `<dl>` grid (P5) already gives the table-scan without the regression risk.

**Reviewed, left by design (no code change):** L5, L8, L9, L17 (see notes above/in the register).

**Still parked (deployment/config, out of code scope):** secrets rotation, queue worker + scheduler + Reverb, multi-worker web server, real courier/mail/storage, host/CORS/session domains, seeded-password change — see the go-live checklist.
