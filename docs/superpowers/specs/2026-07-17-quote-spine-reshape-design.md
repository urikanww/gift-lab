# Quote-Spine Reshape — Design

**Date:** 2026-07-17
**Status:** Draft for review
**Workstream:** A of two (B = delivery & courier / NinjaVan, planned separately)
**Surface:** `QuoteState`, `QuoteService`, `QuotePolicy`, `QuoteController`, `ProofController`,
the quotes/proofs/purchase-orders schema, `QuoteDetailPage.tsx`, `quoteStore.ts`, and new mail infrastructure.

## Problem

Four buyer-facing frictions on the quote lifecycle:

1. **Anyone in the buyer's company can cancel a quote.** The cancel endpoint authorizes on the shared `update` policy (`QuotePolicy.php:33-36` — staff OR own-company buyer). The owner wants cancellation restricted to internal staff/superadmin only. (There is also no cancel button in the UI today — buyer *or* staff — so a cancel control must be built regardless.)

2. **The quote→approval cycle is four hops with two round-trips.** Today: staff send (`DRAFT→SENT`) → buyer accept (`SENT→ACCEPTED`) → staff attach proof (`ACCEPTED→PROOFING`) → buyer approve (`PROOFING→PROOF_APPROVED`). The owner wants the proof to travel *with* the quote so the buyer reviews price and artwork in one decision.

3. **`PO_ISSUED` is named backwards.** The state and its `purchase_orders` record represent the *seller's* invoice/PO issued **to** the buyer (staff-entered `po_ref`, buyer pays against it), not a buyer-issued PO. The name reads as the opposite of standard B2B usage.

4. **The buyer is never told a quote is waiting.** There is *zero* notification infrastructure — no `app/Notifications`, no Mailable, mail driver defaults to `log` (`config/mail.php:17`). A buyer only discovers a waiting quote by opening the app. Cutting clicks does nothing for this; a notification is the real cycle-time lever.

## Goals

- Cancellation is a staff/superadmin-only action, enforced server-side, with a staff UI control.
- Staff can send a proof together with the quote; the buyer approves price + artwork in one action.
- Rename `PO_ISSUED` → `INVOICED` coherently across the whole stack.
- A premium, mobile-responsive buyer email fires whenever there's something new to review.

## Non-goals

- **No buyer-issued-PO capability** (explicitly declined — keep the flow slim). "PO" as a buyer document is out of scope; the rename simply makes the existing seller document honestly an *invoice*.
- **No delivery address or courier work** — that is Workstream B.
- **No change to procurement, production queue, or payment** beyond the state rename touching their references.
- **No forced proof-at-send** — the existing accept-then-proof flow stays as the fallback.

---

## Feature 1 — Staff-only cancel

### Server

Cancellation moves off the `update` ability onto a staff-only gate. `QuoteController::cancel` (`QuoteController.php:126-131`) currently calls `$this->authorize('update', $quote)`. Change it to authorize the existing staff-only ability `manageProduction` (`QuotePolicy.php:43-46`, already registered as a gate in `AppServiceProvider::boot:123`). This is the same gate that guards `send` and `procure`, so cancel joins the staff-only cluster with no new policy method.

The set of cancellable states is unchanged: `QuoteState::nextStates()` (`QuoteState.php:32-45`) already lists `Cancelled` from every pre-production state and deliberately omits it from `READY`/`CLOSED`. `QuoteService::cancel` (`QuoteService.php:364-382`) — transaction, stock return, audit log, `QuoteStateChanged` broadcast — is unchanged.

Authorization lives in one place — the controller's `manageProduction` gate. The `reason` input, currently unvalidated free text on a plain `Request`, gets a small FormRequest (`CancelQuoteRequest`) purely for validation (`reason` → `nullable|string|max:500`), matching the house pattern of the other quote actions; its `authorize()` returns `true` and defers to the controller gate rather than re-checking the role.

### Frontend

Add a **Cancel quote** control to `QuoteDetailPage.tsx`, rendered only under `isStaff` (mirroring the existing staff-only "Issue PO" block at `QuoteDetailPage.tsx:431-464`). It opens a small confirm modal (reuse `ui/Modal`) with an optional reason field, and calls a new `cancelQuote(id, reason)` store action in `quoteStore.ts` (the store currently has no cancel action). The button shows only when the quote is in a cancellable state. Buyers never see it and, with the server gate tightened, cannot reach the endpoint.

---

## Feature 2 — Slim quote + proof flow

### The new path

Sending a proof with the quote is a **new, optional** capability layered onto the existing flow:

- **Slim path** (proof attached at send): `DRAFT → PROOFING` directly. The buyer sees price + artwork together and makes one decision.
- **Existing path** (no proof at send): `DRAFT → SENT → ACCEPTED → PROOFING`, unchanged.

Only one new state-machine edge is required: `DRAFT → PROOFING`. Add `self::Proofing` to the `Draft` entry in `QuoteState::nextStates()` (`QuoteState.php:33`). Every other edge already exists — notably `Proofing → [ProofApproved, ChangesRequested, Cancelled]` is already present, so the buyer's approve and reject targets need no new edges.

### The buyer's two actions (reuse `decideProof`)

Both buyer actions route through the existing `POST /proofs/{proof}/decide` endpoint (`ProofController::decide`, `DecideProofRequest` — already authorizes staff OR own-company buyer). The behavior of each branch keys off a new discriminator, **`accepted_at`** (see acceptance stamping below):

- **Approve** → `QuoteService::approveProof` (`QuoteService.php:289-311`): proof → Approved, quote `PROOFING → PROOF_APPROVED`, broadcasts unchanged. **Addition:** if `accepted_at` is null (slim path — price was never separately accepted), stamp it now, because approving implies accepting the price.

- **Request changes** → `QuoteService::requestProofChanges` (`QuoteService.php:317-327`), branching on `accepted_at`:
  - `accepted_at` **null** (slim path — the rejection could be about price *or* artwork): transition the quote `PROOFING → CHANGES_REQUESTED`. Staff triage from there — `CHANGES_REQUESTED → DRAFT` (already the only forward edge) lets them amend price and/or re-send with a new proof. This reuses `CHANGES_REQUESTED` as the triage bucket; no new state.
  - `accepted_at` **set** (existing path — price already agreed, so this is an artwork-only revision): current behavior preserved — proof → ChangesRequested, quote **stays** `PROOFING`, staff issue a new proof version.

  This single discriminator keeps the existing two-phase flow's semantics intact while giving the slim path a sensible triage.

### Send-with-proof endpoint

Extend `POST /quotes/{quote}/send` (`QuoteController::send`, staff-only via `manageProduction`) to accept an **optional** proof payload — `artwork_version_ref` (validated exactly as `StoreProofRequest`: required string max:2048 when present) and optional `notes`. `QuoteService::send` (`QuoteService.php:232-242`):

- If a proof ref is present: create Proof v1 (reusing `issueProof`'s creation logic), set `price_snapshot_at`, transition `DRAFT → PROOFING`, broadcast `QuoteStateChanged` + `ProofStatusChanged`, dispatch the buyer email (quote-+-proof variant).
- If absent: today's behavior — `DRAFT → SENT`, broadcast, dispatch the buyer email (quote-only variant).

`QuoteService::issueProof`'s existing guard (`QuoteService.php:266-268`, requires `ACCEPTED`/`PROOFING`) is untouched — it still governs the separate "attach a proof later" action on the existing path. The new send path creates the proof through a shared internal helper rather than going through that guard.

### Acceptance stamping

Add nullable `accepted_at` (timestamp) and `accepted_by` (FK users, nullOnDelete) to the quotes table. Set them:

- on the existing `QuoteService::accept` (`SENT → ACCEPTED`), and
- on `approveProof` when `accepted_at` is null (slim path).

This preserves "buyer agreed to the price, who and when" for reporting even when the ACCEPTED dwell is skipped, and doubles as the flow-path discriminator above.

### Frontend

`QuoteDetailPage.tsx` / `quoteStore.ts`:
- Staff **send** UI gains an optional "attach proof" field (artwork ref, reusing the artwork-upload flow) so staff can send quote + proof in one action.
- Buyer view: when the quote is in `PROOFING`, show price **and** proof together with **Approve** and **Request changes** actions (the latter opens a note field). These already map to `decideProof`; the store action exists (`decideProof` in `quoteStore.ts`).

---

## Feature 3 — `PO_ISSUED → INVOICED` full rename

A coherent rename so no layer reads backwards (owner chose the full rename over state-only):

| Layer | From | To |
|---|---|---|
| State enum case | `QuoteState::PoIssued` / `'PO_ISSUED'` | `QuoteState::Invoiced` / `'INVOICED'` |
| quotes.state enum | `'PO_ISSUED'` | `'INVOICED'` (migration to alter the enum + backfill existing rows) |
| Model | `PurchaseOrder` | `Invoice` |
| Table | `purchase_orders` | `invoices` (rename migration) |
| Endpoint | `POST /quotes/{quote}/purchase-order` | `POST /quotes/{quote}/invoice` |
| FormRequest | `IssuePurchaseOrderRequest` | `IssueInvoiceRequest` |
| Controller method | `issuePurchaseOrder` | `issueInvoice` |
| Service method | `QuoteService::issuePurchaseOrder` | `issueInvoice` |
| Store action / UI | `issuePurchaseOrder`, "Issue PO" | `issueInvoice`, "Issue invoice" |
| API docs | `docs/API.md` PO references | invoice |

`PO_ISSUED` remains a transient pass-through (immediately → `CONFIRMED`, `QuoteService.php:348-349`) — that behavior is unchanged, only the name. The `invoices` table keeps its columns as-is (`po_ref`, `invoice_ref`, `terms`, `payment_state`, `amount`…); `po_ref` stays the primary staff-entered reference. Data migration must alter the `quotes.state` enum to include `INVOICED` and update any existing `PO_ISSUED` rows (in practice none rest there, but the migration handles it defensively). Channel names, broadcast events, and the state machine's transition *shape* are unchanged.

This touches ~15 files and two structural migrations (enum alter + table rename). It is mechanical but broad; the risk is a missed reference, mitigated by a full grep sweep for `PoIssued`/`PO_ISSUED`/`purchase_order`/`PurchaseOrder` and the existing spine test suite.

---

## Feature 4 — Buyer notification email

### Infrastructure (all new)

- A queued Mailable `QuoteReadyMail` (implements `ShouldQueue`) with a Blade + inline-CSS, table-based, mobile-responsive template matching the locked premium design (warm paper ground, ivory card, letter-spaced GIFT LAB wordmark, serif headline, bordered summary panel with the total in brand violet, proof strip, single CTA).
- Dispatched via `Mail::to(...)->queue(...)` from `QuoteService` at the trigger points below. Runs on the existing queue worker (Supervisor already runs one for Reverb/jobs).
- **Gmail SMTP config**: wire `config/mail.php` for a `smtp` mailer against `smtp.gmail.com:587` (TLS), reading `MAIL_USERNAME` / `MAIL_PASSWORD` (a Gmail **app password**) / `MAIL_FROM_ADDRESS` from env. Add these keys to `.env.example` with placeholders and document the app-password step. Until real credentials are added, `MAIL_MAILER=log` keeps it queuing/logging harmlessly — the stub-until-provisioned pattern the repo already uses for external services. **Owner will add real credentials later.**

### Triggers (fire whenever the buyer has something new to review)

- **Quote sent, quote-only** (`DRAFT→SENT`): "Your quote is ready" — no proof strip.
- **Quote sent with proof** (slim, `DRAFT→PROOFING`): "Your quote & proof are ready" — full design.
- **Proof issued on the existing path** (`ACCEPTED→PROOFING`, `issueProof`): "Your proof is ready to review".

One Mailable, content adapts by variant. No double-send: the slim path emits a single quote+proof email.

### Recipient

The buyer who requested the quote — `quotes.created_by` (a buyer user with an email). v1 sends to that single address; the design leaves room to extend to all company buyer seats later (noted, not built).

### Proof thumbnail (real, per owner)

The email shows the actual proof artwork, not a placeholder. Proofs store `artwork_version_ref` (an object-store key; `proofs` migration:26). Email clients require an absolute, unauthenticated image URL, and the email may be opened days later, so:

- Serve the thumbnail through a **signed image route** with a long TTL, mirroring the existing signed artwork-preview (`GET /uploads/artwork/preview`) and signed track-view patterns. The signature is the auth; no session needed.
- If the `artwork_version_ref` is not a web-renderable raster image (it may be a design/print file), fall back to the branded "v1" placeholder tile from the locked design. (Thumbnail generation for non-image artwork is out of scope — fast-follow.)

### CTA

Deep-links to the quote page (login-gated — approving is an authenticated action), not a public link.

---

## Data-model changes (summary)

1. `quotes`: add `accepted_at` (nullable timestamp), `accepted_by` (nullable FK users, nullOnDelete). Alter `state` enum `PO_ISSUED → INVOICED` + backfill.
2. Rename table `purchase_orders → invoices` (and the model).
3. No other schema changes. No new columns for cancel (reason is already only audit-logged), none for the email.

---

## Testing

**Backend (Pest, Feature):**
- **Cancel authz:** staff can cancel (allowed states), superadmin can cancel, **buyer gets 403** (new — no Feature test guards this today), cancel refused from `READY`/`CLOSED`, stock returned on cancel.
- **Slim flow:** send-with-proof transitions `DRAFT→PROOFING` and creates Proof v1; buyer approve → `PROOF_APPROVED` and stamps `accepted_at`; buyer request-changes on a slim quote (`accepted_at` null) → `CHANGES_REQUESTED`; request-changes on an accepted quote → stays `PROOFING` with a new proof version (regression guard for the existing behavior); send-without-proof still `DRAFT→SENT`.
- **Acceptance stamp:** `accepted_at`/`accepted_by` set on both normal accept and slim approve.
- **Rename:** the spine flow test (`QuoteFlowTest`) passes end-to-end against `INVOICED`; `POST /quotes/{quote}/invoice` works; a grep-driven check that no `PO_ISSUED`/`PurchaseOrder` references remain.
- **Email:** `Mail::fake()` asserts `QuoteReadyMail` queued to the right recipient with the right variant at each trigger; the signed thumbnail route returns the image for a valid signature and 403 for a bad one.

**Frontend (Vitest):**
- Staff-only cancel control renders for staff, not for buyers; calls `cancelQuote`.
- Buyer PROOFING view shows Approve + Request changes and calls `decideProof`.
- "Issue invoice" label/flow (renamed) still submits.

## Risks & open items

- **Rename breadth** — the one broad, mechanical change; a missed reference is the main risk. Mitigated by grep sweep + the existing spine tests. Confirmed in scope by the owner.
- **Table rename migration** — `purchase_orders → invoices` on a populated table; the migration must move data safely (a rename, not drop/recreate). Down-migration provided.
- **Gmail SMTP for production** — Gmail SMTP is fine for low volume but has send limits and deliverability caveats; acceptable for launch, swappable to SES/Postmark later via the same config. Owner adds the app password.
- **Non-image proof thumbnails** — fall back to the placeholder tile; true thumbnailing deferred.
- **Recipient = created_by only** — multi-seat companies get one recipient in v1.

## Suggested build order (for the plan)

1. Rename `PO_ISSUED → INVOICED` (do the broad mechanical change first, on a green tree, before layering new behavior on the same files).
2. Staff-only cancel (server gate + FormRequest + UI).
3. Acceptance stamping + the `DRAFT→PROOFING` slim path (state machine, send-with-proof, decideProof branching, buyer UI).
4. Buyer email (mail config + Mailable + template + signed thumbnail route + triggers).
