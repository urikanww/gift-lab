# Feature A — Configurable price-first vs proof-first, per order

**Date:** 2026-07-30
**Source:** `docs/superpowers/specs/2026-07-29-giftlab-post-walkthrough-roadmap.md` § "Feature A" + owner decisions (this session).
**Scope:** Feature A only. Does not touch Batch 0, Feature B, or Features C/D.

---

## Owner decisions (confirmed this session)

- **A-1 — Price visibility in proof_first:** show the price **read-only** before proof approval ("review the art first, agree the price after"). Not hidden.
- **A-2 — Lock at send:** `approval_order` is editable by staff only while the order is **DRAFT**. Once sent it is locked for staff — **but a superadmin (admin) may still change it after send** (mirrors the existing `amend()` superadmin override).
- **A-3 — Default scope:** a single **global** default of `price_first`, with a per-order override. No per-company default, no `companies` column.

---

## Problem

The flow is hardwired to price-first: buyer accepts the **price** (Sent → Accepted), then approves the **proof** (Proofing → Proof-approved), then the order is invoiced. Some jobs need the buyer to sign off the **artwork first**, then agree the price.

The state machine already contains everything the artwork-first route needs — the `ARTWORK_APPROVED` state exists, `accept()` already lands on `PROOF_APPROVED` when called from `ARTWORK_APPROVED`, and `sendProofs()` already moves `DRAFT → PROOFING`. **But the ordering is implicit** — determined by whichever action staff/buyer happen to fire first — and **unenforced**. Nothing stops a job meant to be proof-first from being price-accepted first, or vice-versa.

Feature A makes the ordering an **explicit, persisted, per-order choice** and **enforces** it. It adds **no new states and no new transition-table edges.**

---

## Decision

Add a per-quote `approval_order ∈ { price_first (default), proof_first }`. Staff set/flip it while the order is DRAFT (superadmin may override after send, per A-2). The existing `QuoteState` transition table is unchanged; ordering is enforced at the `QuoteService` action entry points, which are the only layer that can see both the flag and whether the order has proof-needing lines.

### Why enforce at the service, not in the enum (rejected alternatives)

- **`QuoteState::nextStates()`** is data-only — it cannot read the per-quote flag or the order's line items, and the plain-stock no-op depends on `hasProofNeedingLines()`. Rejected.
- **A dedicated transition-policy class** is over-engineered for two guard conditions and moves logic away from the `send`/`sendProofs`/`accept` methods that already own these actions. Rejected.
- **Service-level guards (chosen)** sit exactly where the flag and line data are both in hand.

---

## Flows

- **price_first (unchanged default):** Draft →`send`→ Sent →buyer `accept`→ Accepted →`sendProofs`→ Proofing →buyer `approve`→ Proof-approved → (Invoiced → Confirmed).
- **proof_first (new):** Draft →`sendProofs`→ Proofing →buyer `approve`→ Artwork-approved →buyer `accept`→ Proof-approved → (Invoiced → Confirmed).
- **plain-stock (no proof-needing line, either flag):** Draft →`send`→ Sent →buyer `accept`→ (auto) Proof-approved. `approval_order` is a **no-op** here.

---

## Data

- New enum `App\Enums\ApprovalOrder`:
  - `PriceFirst = 'price_first'`
  - `ProofFirst = 'proof_first'`
- Migration `add_approval_order_to_quotes`: `quotes.approval_order` `string(16)` `NOT NULL DEFAULT 'price_first'`, added after `reminded_phase`. Existing rows adopt the default, so they behave exactly as today (A-3). Cast the column to `ApprovalOrder` on the `Quote` model.
- `down()` drops the column.

---

## State machine & enforcement

`QuoteState` and its `nextStates()` table are **unchanged**. Enforcement lives in `QuoteService`, and every guard bites **only when the order has proof-needing lines** (`hasProofNeedingLines()`). A plain-stock order is a no-op for both orderings.

Two small predicates on `QuoteService` (or `Quote`) keep the guards readable:
- `requiresProofFirst(Quote)` ⇔ `approval_order === ProofFirst && hasProofNeedingLines(quote)`
- `requiresPriceFirst(Quote)` ⇔ `approval_order === PriceFirst && hasProofNeedingLines(quote)`

Guards:

1. **`send()`** (price ask → SENT): reject when `requiresProofFirst`. Message: *"This order is set to proof-first; send the artwork proof to the buyer before asking for the price."*
2. **`sendProofs()`** (proof ask): reject when `requiresPriceFirst && accepted_at === null` (price not yet agreed). Message: *"This order is set to price-first; the buyer must agree the price before proofs are sent."* (Allowed once `accepted_at` is set — the normal price_first proof round, incl. from `CHANGES_REQUESTED`.)
3. **`accept()`** from `SENT`: reject when `requiresProofFirst`. Message: *"This order is set to proof-first; approve the artwork proof before agreeing the price."* Acceptance from `ARTWORK_APPROVED` stays allowed (it completes the proof_first pair).

Net effect:
- **price_first:** proof-before-price is blocked at `sendProofs()`; the order can never reach `ARTWORK_APPROVED`, so `recomputeProofState()` (which chooses `ARTWORK_APPROVED` vs `PROOF_APPROVED` by `accepted_at`) is left untouched and still correct.
- **proof_first:** price-before-proof is blocked at `send()` and `accept()`; the order goes Draft → Proofing → Artwork-approved → (accept) → Proof-approved.
- **plain-stock:** guards do not bite; both flags collapse to the price-only path with the existing auto-skip in `accept()`.

The auto-skip guard `hasProofNeedingLines()` in `accept()` is retained as-is; it short-circuits both orderings for plain stock.

---

## Setting the flag (A-2)

- `QuoteService::setApprovalOrder(Quote $quote, ApprovalOrder $order): Quote`.
  - Allowed when `state === DRAFT`, **or** the acting user `isSuperadmin()` (admin override after send).
  - Otherwise throws `DomainRuleException("Approval order is locked once the order is sent.")`.
  - Audit-logged (`quote.approval_order_changed`, from → to), same shape as other quote edits.
- Endpoint: `PATCH /api/quotes/{quote}/approval-order`, body `{ approval_order: 'price_first' | 'proof_first' }`.
  - New `SetApprovalOrderRequest` validates the enum value.
  - Authorized with `manageProduction` (staff), matching `send()`/`confirmStock()`.
- `QuoteResource` exposes `approval_order` so the SPA can render the current choice.

---

## Buyer UX

- **A-1:** in proof_first the price is shown **read-only** before proof approval — "review the artwork first, agree the price after". Never hidden.
- The buyer "Next step" card copy reflects which approval is being asked **now**:
  - price_first @ SENT → "Review and accept your quote."
  - proof_first @ PROOFING → "Review and approve your artwork proof."
  - proof_first @ ARTWORK_APPROVED → "Artwork approved — now review and accept your quote."

---

## Staff UX

- On the **DRAFT** order page, a segmented control near "Send to buyer": **Approval order: Price first / Proof first**. Disabled once the order has left DRAFT (a superadmin sees it enabled, per A-2).
- "Send to buyer" branches on the flag:
  - price_first → `POST /api/quotes/{id}/send` (as today).
  - proof_first with proof-needing lines → the proof round (`POST /api/quotes/{id}/proofs/send`, after staging).
  - plain-stock proof_first → falls back to `/send` (nothing to proof).

---

## Milestone emails & chase

- Milestone copy (Accepted / Proof-ready / Proof-changes) already exists; the ordering only changes **when** each fires. No new mailables.
- `ChaseUnansweredOrders` keys off **state**, not `approval_order`:
  - price wait = `SENT` **or** `ARTWORK_APPROVED`;
  - proof wait = `PROOFING` with at least one `SENT` proof.
  Both orderings traverse those states, so the two ladders compose unchanged. `reminded_phase` already resets the counter when an order moves between the price and proof waits (M16). **Verify with a per-ordering chase test** — expected to pass without code change.

---

## Testing (TDD)

Backend (Pest) — the heart of the feature:

- `approval_order` defaults to `price_first` on a freshly created quote.
- `setApprovalOrder`: succeeds on DRAFT; rejected for staff once sent; succeeds for a superadmin after send; audit entry written.
- **price_first** rejects `sendProofs()` while `accepted_at === null`; allows it after `accept()`.
- **proof_first** rejects `send()` and rejects `accept()` from `SENT`; allows the Draft → Proofing → Artwork-approved → accept → Proof-approved path.
- Both orderings reach `PROOF_APPROVED` with `accepted_at` set (invoice-ready).
- **plain-stock** (no proof-needing line) is a no-op under both flags: Draft → Sent → accept → Proof-approved.
- Chase: a price_first order waiting in SENT chases on the price ladder; a proof_first order waiting in PROOFING chases on the proof ladder; a proof_first order in ARTWORK_APPROVED chases on the price ladder.

Frontend (Vitest/RTL):

- Toggle persists the choice (PATCH fires, resource reflects it).
- Toggle is disabled once the order has left DRAFT.
- "Next step" copy matches the ordering + current state (three cases above).

---

## Migration & compatibility

- Pure additive column with a default — no backfill, no data migration. Every existing quote reads `price_first` and behaves exactly as before.
- No change to `QuoteState`, its transition table, `recomputeProofState()`, or the invoice guard.
- **Both orderings are enforced per the flag** (owner: "whichever staff think it is"). `price_first` genuinely requires the price to be agreed before proofs are sent (guard on `sendProofs` when `accepted_at === null`); `proof_first` blocks the price ask/accept before proof approval. Plain-stock stays a no-op.
- Pre-Feature-A tests that exercised the proof-first path under the *implicit* default (`SlimQuoteFlowTest` slim-path cases, `SendProofsTest`) are re-labeled `proofFirst()` / given a consistent `accepted_at` — behaviour-preserving (assertions unchanged); the explicit flag just names the ordering they always exercised.
- Do not delete or disturb seeded/walkthrough data.

---

## Out of scope

- Batch 0 items, Feature B (ref/tracking unification), Features C/D (courier/production rework).
- Any per-company default (explicitly rejected under A-3).
- New quote states or transition edges (none needed).

---

## Open questions

None outstanding — A-1, A-2, A-3 resolved above.
