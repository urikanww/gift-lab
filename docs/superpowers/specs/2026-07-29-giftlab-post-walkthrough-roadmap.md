# Gift-Lab — Post-Walkthrough Roadmap & Design Specs

**Date:** 2026-07-29
**Source:** live full-app walkthrough (`docs/flow-audit-2026-07-28/WALKTHROUGH-FEEDBACK.md`) + owner feedback.
**Purpose:** a self-contained plan another session can execute. Each numbered item is designed to become its own spec → implementation-plan → build cycle. Owner decisions are flagged **⚠️ OWNER**.

**How to use this doc:** work top-to-bottom. Batch 0 first (cheap, clears noise), then Features A → B → C/D. Each feature section is a mini-spec: problem, decision, approach, data/state/UI, migration, testing, open questions. Do NOT implement all at once — take one section, write its plan, build, verify, move on.

---

## Findings ledger (from the run)

| ID | Sev | Area | Rolls into |
|----|-----|------|-----------|
| F10 | 🔴 | Returned-parcel resolution has no UI | Feature D |
| F7 | 🟠 | Accepted email promises "artwork proof" on plain-stock orders | Batch 0 |
| F8 | 🟠 | Commit disabled until PO entered, no "required" hint | Feature C/D (or Batch 0) |
| F4 | 🟠 | Buyer sees raw state names + "step N of 8" | Batch 0 |
| F9 | 🟠 | "Delivered·unpaid" tile → unfiltered quotes list | Batch 0 |
| F3 | 🟠 | Cart doesn't itemize the setup fee (order page does) | Batch 0 |
| F1 | 🟡 | Register validation copy (lumped, Laravel-default) | Batch 0 |
| F2 | 🟡 | PDP "1 pcs" (should be "1 pc") | Batch 0 |
| F6 | 🟡 | Email "1 item(s), N unit(s)" pluralization | Batch 0 |
| F11 | 🟡 | Reply-to + footer text hardcoded → env | Batch 0 |
| F12 | 🟠 | Book-NinjaVan-shipment dialog can't scroll to the Book button | Feature C/D |
| F13 | 🟡 | SG address city/state/country should prefill + disable | Feature C/D |
| F14 | 🟡 | "Reorder from a past quote" rail should move off the home page | Batch 0 |

Owner design questions raised in feedback are folded into the relevant feature below.

---

## Batch 0 — Quick wins (copy/config/UX)

Small, low-risk, independent. Ship first. No state-machine or data-model change.

- **F1** — Register: per-field inline validation errors + humanised copy ("Please enter your company name" not "The company name field is required").
- **F2** — PDP volume-pricing tier: singular "1 pc" (proper pluralization).
- **F3** — Cart Estimate: add the setup/"Personalisation" line so `items + fees = subtotal` (mirror the order-detail page, which already does this). ⚠️ OWNER: also decide whether a **blank/uncustomised** line should carry the SGD 25 artwork-setup fee at all.
- **F6** — Emails: pluralize naturally ("1 item, 30 units") via `Str::plural`. Blade: `resources/views/mail/quote-ready.blade.php`.
- **F7** — Accepted milestone email: **conditional copy**. Proof-needing order → "we're preparing your proof". Plain-stock (auto-skips proofing) → "we're preparing your order for production/invoicing". Source: `app/Enums/OrderMilestone.php` (Accepted case) — needs a per-quote branch, so the mail must know whether the order has proof-needing lines.
- **F9** — Dashboard "Delivered·unpaid" tile: link to a **filtered** list (delivered + outstanding balance), or add an unpaid column to the quotes list. Today it opens unfiltered `/quotes`.
- **F11** — Mailer identity to env: add `MAIL_REPLY_TO` and a configurable footer line ("Just reply to this email if you need us" / "Internal notification"). Wire into the mail layout instead of hardcoding.
- **F14** — Remove the "Reorder from a past quote" rail from the storefront home; surface reorder as a **button/section on the account (My Orders) page** instead. Component: `frontend/src/components/home/ReorderRail.tsx` → move/relink.
- **Also fold in F4 here if cheap:** buyer-facing status labels + drop the raw "step N of 8" counter (a friendly progress indicator instead). If it turns out to touch many surfaces, split F4 into its own small spec.

**Testing:** unit/snapshot for the mail copy branches (F6/F7); component tests for register validation (F1) and cart estimate line (F3); a route/redirect test for F9; a smoke check that reorder still works from its new home (F14).

---

## Feature A — Configurable price-first vs proof-first, per order

**Owner decision (given):** support **both** orderings, **staff-controllable per order**. Default = price-first (current behaviour).

### Problem
Today the flow is hardwired: buyer accepts **price** first (Sent → Accepted), then approves **proof** (Proofing → Proof-approved), then Confirmed. Some jobs want the buyer to approve the **art first**, then agree price.

### Decision
Add a per-quote setting `approval_order` ∈ { `price_first` (default), `proof_first` }. Staff set/flip it while the order is still **DRAFT** (before "Send to buyer"). The quote state machine branches on it.

### Flows
- **price_first (unchanged):** Draft → Sent → *buyer accepts price* → Accepted → Proofing → *buyer approves proof* → Proof-approved → Confirmed.
- **proof_first (new):** Draft → Sent(proof) → Proofing → *buyer approves proof* → Proof-approved → *buyer accepts price* → Accepted → Confirmed.
- **plain-stock (no proof-needing line):** both collapse to price-only (Sent → Accepted → Proof-approved auto-skip). `approval_order` is a no-op here.

### Data
- `quotes.approval_order` (string/enum, default `price_first`), set nullable-safe with a default so existing rows behave as today.

### State machine
- The hard part. `QuoteState` transitions + `QuoteService::accept()` / `sendProofs()` / the proof-decision path must consult `approval_order` to decide the *next* legal state. Keep the transition table honest — don't let a proof_first order be price-accepted before its proof is approved (and vice-versa).
- The auto-skip guard (`hasProofNeedingLines`) stays; it short-circuits both orderings.

### Buyer UX
- ⚠️ OWNER: in **proof_first**, does the buyer see the **price at all** before approving the proof? Options: (a) hide price until proof approved; (b) show price read-only, "review art first, agree price after". Recommend (b) — transparent, less surprising.
- Buyer "Next step" card copy must reflect which approval is being asked for right now.

### Staff UX
- A control on the DRAFT order page ("Approval order: Price first ▸ / Proof first") — a simple toggle/segmented control near "Send to buyer".
- Once sent, lock it (changing mid-flight would strand state). ⚠️ OWNER: allow changing after send? Recommend **no** — lock at send.

### Milestone emails
- Accepted / Proof-ready / Proof-changes copy already exist; ordering just changes when each fires. Verify the chase reminders (`quotes:chase`) still key off the right "waiting on price" vs "waiting on proof" phase per ordering — `ChaseUnansweredOrders` already separates the two ladders, so this should compose.

### Testing
- State-machine tests for both orderings incl. illegal-transition rejection (price-accept blocked before proof in proof_first). Plain-stock no-op test. A per-order toggle persistence test. Chase-phase test per ordering.

### Open questions ⚠️ OWNER
1. Price visibility during proof_first (recommend read-only shown).
2. Lock `approval_order` at send (recommend yes).
3. Any per-**company** default, or always price_first unless staff change it? (recommend global default price_first, per-order override only.)

---

## Feature B — Unify order reference & tracking code

**Owner decision (given):** combine order-ref and tracking-code into **one** identifier. Rationale: the public tracker already requires the **buyer email** to verify before showing anything, so a single id is safe to expose.

### Problem
Two ids per order today: `reference` (e.g. `MANVQ3MT1M`, account-scoped identity) and `tracking_code` (e.g. `GL-X78J0F`, PII-free public token). Confusing; two things to communicate.

### Decision
Collapse to **one** id used everywhere (order pages, emails, QR, public tracker). Public tracker keeps its **email verification gate**, so exposing the single id is acceptable.

### ⚠️ OWNER decision — which format survives
- **Option 1 (recommend): keep `reference` (`MANVQ3MT1M`)** as the single id; retire `tracking_code`. Cleaner, already the buyer-facing order name.
- Option 2: keep the `GL-` tracking format as the single id.
- Whichever survives must be collision-safe and remain non-guessable-enough given the email gate.

### Data & migration
- Pick the surviving column; drop/deprecate the other. Backfill nothing new (ids already exist). 
- **Migration risk:** any already-shared QR/track links using the old code break. Pre-production, so acceptable — confirm no external party holds old links.
- Update: order-detail (staff+buyer), all mailables + blades (they print both today), the QR component (`frontend/src/components/TrackingQr.tsx`), the `/track` lookup (`TrackPage` + `NinjaVanWebhookController` does NOT use it — it uses `consignment_ref`, so courier side is unaffected), and any `Quote::trackingStage`/resource exposure.

### Testing
- Tracker still gates on email + resolves by the unified id. Emails/QR render the one id. No lingering `tracking_code` references. Webhook (consignment-ref based) untouched.

---

## Feature C + D — Courier / production / shipment rework

The big one. Absorbs **F10 (🔴)**, **F8**, **F12**, **F13**, and owner feedback #5 (queue UI rework), #8 (separate push-to-courier from make-queue), #9 (shipment grouping). Design as **one** effort — these all touch the same surface.

### C1 — Shipment grouping control (owner #9)

**Owner decision (given):** default = **one shipment per order** (all items together); staff can **opt to split** per item.

**Current behaviour (from the run):** the system creates a **production job per line**, so a 2-line order already produced **2 jobs → 2 consignments** — i.e. today it *splits by default*. This decision **inverts** that.

**Design:**
- Introduce a **Shipment** grouping: one shipment = one consignment = ≥1 production jobs/lines. Default: all of an order's jobs belong to **one** shipment → one NinjaVan booking.
- Staff action "Ship this item separately" splits a line/job into its own shipment.
- **Delivery fee:** already computed once on total chargeable weight (one line in the quote) — good, keep as-is for the combined case. ⚠️ OWNER: when staff **split** into N shipments, does the buyer get charged **N delivery fees** or still one? (Today one fee even for multiple parcels — potential under-charge.) Recommend: recompute delivery per shipment when split, or surface it for staff confirmation.
- Production make-queue (making the items) stays per-item; **shipping** groups them. This is the C↔D seam.

### D1 — Separate "push to courier" from the production make-queue (owner #5, #8)

**Problem:** today booking a shipment lives *inside* the production-queue card, and a returned parcel also lingers there (root of F10). Muddled.

**Design:** split into distinct surfaces/sections:
1. **Make queue** — items being produced (scan-to-advance, start/finish). No courier actions.
2. **Ship desk** — items *ready to ship* → book courier (per shipment, per C1), print label. This is where "Create NinjaVan shipment" moves.
3. **In-transit / delivered** — shipped parcels awaiting the courier's delivered webhook (today's "Awaiting delivery").
4. **Needs attention (returned/failed)** — parcels the courier returned → **the F10 resolution lives here**.

### D2 — Returned-parcel resolution UI (**F10 🔴**)

**Problem (blocker):** backend `resolveReturn`/`returnParcel` (reship / close / cancel-&-credit, M15) is fully built & tested, but **nothing in the frontend calls it**. A returned parcel is stuck: only "Mark delivered" (which the backend rejects). No UI path to resolve.

**Design:**
- In the **Needs-attention** surface (D1.4), each returned parcel shows the courier status + three actions calling the existing endpoint `POST /production-jobs/{job}/resolve-return`:
  - **Reship** — re-queue for a fresh consignment (backend clears courier footprint, job → IN_PRODUCTION).
  - **Close (write off)** — accept the loss, close the job.
  - **Cancel & credit** — void this parcel's share, restock its lines, credit only what was collected (proportional; M15). On a multi-parcel order, only this parcel; order stays live.
- Route returned parcels **out of** the plain in-transit list (the `AwaitingDeliveryPanel` comment already assumes they're not there).
- Remove/replace the misleading "Mark delivered" affordance for returned parcels.

### D3 — Book-shipment dialog fixes (F12, F13)

- **F12:** the "Book NinjaVan shipment" dialog is taller than the viewport; the **Book shipment** button sits below the fold with no scroll (I had to keyboard-Tab to it). Fix: **scrollable dialog body + sticky action footer** so the primary button is always reachable.
- **F13:** SG-only courier — **prefill City=Singapore, State=Singapore, Country=SG and render them read-only/disabled**, so staff can't mis-enter and break a booking. (Today City is empty + editable.)

### D4 — Commit PO-required hint (F8)

- Commit is disabled until a PO is entered, with no indication it's required. Mark the PO field **required** (asterisk + helper "PO required to raise the invoice"), or show a tooltip on the disabled Commit button. (Lives near commit, not strictly courier — fold here or into Batch 0.)

### Data (C/D)
- New **Shipment** entity (id, quote_id, consignment_ref, carrier, status, label_url, courier-status fields) grouping jobs; migrate current per-job consignment fields onto it, or make Shipment 1:1 with a job for the split case and 1:many for combined. ⚠️ OWNER + implementer: model exact shape — this is the crux of C1.
- Backend already has `JobState::Returned` (terminal, from cancel-credit) — reuse.

### Testing (C/D)
- Combined-by-default booking (1 consignment for N lines); staff split → N consignments; delivery-fee behaviour on split (per OWNER decision).
- F10: each disposition (reship/close/cancel-credit) drives the right backend call + state; multi-parcel cancel-credit isolates one parcel; returned parcel leaves the in-transit list and appears in needs-attention.
- Dialog: Book button reachable at laptop viewport; SG fields prefilled+disabled.

### Open questions ⚠️ OWNER
1. Split shipments → N delivery fees or one? (recommend recompute per shipment).
2. Shipment model shape (1:1 vs 1:many jobs) — implementer to propose, owner to confirm.
3. Is the 4-surface split (make / ship / in-transit / needs-attention) the right cut, or keep fewer tabs?

---

## Suggested execution order (for the other session)

1. **Batch 0** — quick wins (F1/F2/F3/F6/F7/F9/F11/F14, + F4 if cheap). Ship, verify, done.
2. **Feature B** — unify ref/tracking (self-contained; makes emails cleaner). Confirm the ⚠️ format decision first.
3. **Feature A** — price/proof order toggle (isolated state-machine). Confirm the 3 ⚠️ decisions first.
4. **Feature C + D** — courier/production rework incl. F10. Biggest; design its data model carefully; confirm the ⚠️ decisions first. **F10 is the 🔴 blocker — if it must ship sooner, D2 alone can be pulled forward as a stopgap** (wire the resolution actions into the existing queue card) ahead of the full D1 restructure.

Each of the four becomes its own spec → implementation-plan → build. This doc is the index.

---

## Owner decisions to confirm before building (consolidated)

- **F3:** setup fee on a fully blank line — keep or drop?
- **A-1:** proof_first — show price read-only before proof approval? (rec: yes)
- **A-2:** lock `approval_order` at send? (rec: yes)
- **A-3:** per-company default or global? (rec: global default price_first)
- **B:** which id survives — `reference` (rec) or `GL-` tracking format?
- **C-1:** split shipments → N delivery fees or one? (rec: recompute per shipment)
- **C-2/D:** Shipment model shape; number of production surfaces.
