# Per-Line-Item Proofs — Design

**Date:** 2026-07-24
**Status:** Draft for review
**Surface:** `proofs` table + `Proof`/`ProofState`, `QuoteService` (issue/send/approve/
request-changes), `QueueService` (job build + print-file resolution), `ProofController`,
`ProofResource`, `QuoteResource`, `ProofCompositeService`, `ReminderSchedule`,
`QueueService`/`ProductionJob`; frontend `QuoteDetailPage` (staff panel + buyer
review), `OrderStatus`, new per-line proof components.

## Problem

Proofs are **order-level**. `proofs` keys on `quote_id` with `unique(quote_id,
version)` and a single `artwork_version_ref` (`2026_07_01_000012_create_proofs_table.php`).
An order therefore has one proof lineage (v1 → v2 → v3) and **one approved
artwork for the whole order**. `QuoteService::issueProof()` takes one ref;
`approveProof()`/`requestProofChanges()` drive the entire quote's state off that
one proof (`QuoteService.php:583,642,686`).

This breaks any order with **multiple customized items needing different
artwork**. `QueueService::buildJobsForQuote()` (`QueueService.php:60-110`) already
leans per-line for the 3D track — each `MODEL_3D` line gets its own job and its
own decal (`customization.print_file_ref`) — but **all UV/printed lines fold into
one bucket and print the single approved proof artwork** (`resolveArtworkRef`,
`:113+`). So several different UV items on one order can only ever share one
signed-off file. Staff sign off one artwork for the whole order; the buyer
reviews one artwork for the whole order.

The fix is to make the proof grain match reality: **one artwork line = one proof
lineage**, reviewed and approved independently, with the order advancing once
every artwork line is resolved.

## Goals

- Every line item that needs artwork gets its **own** proof lineage (versions,
  state, approval), UV and 3D alike — one unified model.
- Buyer reviews each item's artwork independently: per-item **Approve** /
  **Request changes**, plus an **Approve all remaining** shortcut.
- Staff prepare artwork per line (staged), then **one "Send proofs" action**
  emails the buyer **once** per round.
- The order never freezes on a single lagging item: it advances when every
  artwork line is **approved or dropped**.
- Production prints each item's own approved artwork; the shop floor keeps a
  single batched UV job (files labelled per item) and per-line 3D jobs.

## Non-goals

- **No order splitting.** One order = one price, one invoice, one commit. We do
  NOT ship/print item 1 while item 5 is still being designed; "partial release"
  is achieved via *approve-or-drop*, not sub-orders.
- **No legacy migration.** Pre-launch, no live proofs to preserve — clean schema,
  reseed.
- Plain (non-customized) stock lines are never proofed.

## Data model

### `proofs` table (clean schema; reseed)

- Add `line_item_id` — **NOT NULL**, FK to `line_items`, `cascadeOnDelete`.
- Keep `quote_id` (denormalized) for order-wide queries and broadcasts.
- Replace `unique(quote_id, version)` → **`unique(line_item_id, version)`**.
  Versions are per line (line A's v1/v2/v3 are independent of line B's).
- Index `(quote_id, state)` retained for order-level rollups.

### `ProofState` enum — add `DRAFT`

Lifecycle **per line**:

```
DRAFT ──send──▶ SENT ──approve────▶ APPROVED
                 │
                 └──request_changes─▶ CHANGES_REQUESTED ──(staff issues new version)──▶ DRAFT ─▶ …
```

- `DRAFT` = staged by staff, **buyer not yet emailed**. Created when staff attach
  artwork to a line.
- `SENT` = emailed to the buyer, awaiting their decision.
- `APPROVED` / `CHANGES_REQUESTED` = buyer's decision.
- A revision after `CHANGES_REQUESTED` opens a **new version** in `DRAFT`.
- `proofStateTone`/`humanizeState` gain a tone for `DRAFT` (neutral).

### `production_jobs.artwork_refs` (list)

- Add `artwork_refs` JSON — an ordered list of `{ line_item_id, product_name,
  ref }`. The single `artwork_ref` column is removed (reseed).
- 3D job: one entry (that line's decal). UV job: one entry per approved UV line.

## Domain rules

### `LineItem::needsProof(): bool`

True when the line carries **any** customization — designer artwork OR
`buyer_uploaded` finished-look (staff proof those before print) — i.e.
`customization` present and mode ≠ plain/blank stock. Plain stock lines return
false and never get a proof row.

### Order-state aggregation — `Quote::recomputeProofState()`

Called after every per-line proof decision, every line drop, and every amend that
adds/removes an artwork line. Considers only **artwork lines that are not
dropped** (`needsProof() && line_state !== DROPPED`).

Precedence:
1. Any such line has an open `SENT` proof, or a customized line is **not yet
   prepared** (no non-terminal proof) → order stays in `PROOFING` (or moves into
   it from `ACCEPTED`/`DRAFT` on send).
2. Else any such line's latest proof is `CHANGES_REQUESTED` → order
   `CHANGES_REQUESTED`.
3. Else **every** artwork line is `APPROVED` (dropped lines excluded) → order
   transitions to the artwork-approved outcome, branching on `accepted_at` exactly
   as today: `accepted_at === null` → `ARTWORK_APPROVED`; else → `PROOF_APPROVED`
   (`QuoteService.php:663-667`).

This replaces the current single-proof transitions inside `approveProof()` /
`requestProofChanges()`; those now mutate one line's proof and delegate the order
transition to `recomputeProofState()`.

Edge — **no artwork lines at all**: order needs no proofing; the plain-quote path
(`send()` → `SENT` → accept) is unchanged.

Edge — **drop line**: dropping a line voids its open (`DRAFT`/`SENT`/
`CHANGES_REQUESTED`) proof and re-runs `recomputeProofState()`; if it was the last
blocker the order advances. This is the anti-freeze exit.

Edge — **amend adds a customized line** (superadmin, any stage): the new line is
`needsProof()` with no proof → order falls back to `PROOFING` until it is prepared,
sent, and approved.

## Staff flow — prepare per line, one send

The merged status card (from the `2026-07-24` order-detail rework) lists each
**artwork line** with:

- product name + the line's current proof state badge (`Not prepared` / `Staged` /
  `Sent` / `Approved` / `In changes` / `Dropped`);
- an uploader (`ProofFileInput`) that **stages** a `DRAFT` proof for that line;
- **"Use existing artwork"** reuse, defaulting to that line's own designer artwork,
  still offering the order's other refs;
- a **drop-line** action (existing line-drop) for the anti-freeze exit.

Above the list:

- **Blocker breakdown** — `Awaiting buyer: N · In changes: N · Not prepared: N ·
  Approved: N`. The anti-stall surface: staff see exactly what holds the order.
- **"Send proofs to buyer (N staged)"** — enabled only when ≥1 line is `DRAFT`;
  flips all `DRAFT` → `SENT`, moves the order into `PROOFING`, and fires **one**
  batched email. Disabled at 0.
- **Unsent-DRAFT warning** — if any `DRAFT` proof exists and the staff leave it
  unsent, a persistent inline notice ("N item(s) staged but not sent to the
  buyer"). Kills the silent stall.

Revisions: after a line's `CHANGES_REQUESTED`, staff stage a new version (`DRAFT`)
and Send again — one email for that round (single or multiple revised lines).

Approved-artwork callout + proof history become **per line** (grouped by item).

## Buyer flow — per-item review

The buyer review card lists each **artwork line** with an open proof:

- product name, artwork shown **inline** (composited on the product photo);
- its own **Approve** and **Request changes** (notes + optional reference images,
  reusing the existing multi-attachment `change_refs` path);
- lines in `CHANGES_REQUESTED` show "being revised — we'll send an updated proof",
  not actionable.

Above the list:

- **Overall banner + progress** — `1 of 3 approved · 1 awaiting you · 1 being
  revised`. The buyer always knows what's left and whose court it is in.
- **"Approve all remaining"** — approves only the items currently **awaiting the
  buyer** (`SENT`), never ones already in changes; the label says so. One batch
  endpoint, one transaction, one `recomputeProofState()`.

Staggered readiness: approve item 1 now, leave; the revised item 2 emails later;
return and approve the rest; the order advances when the last is
approved-or-dropped. Partial approvals persist.

Artwork-first is preserved: all artwork approved with `accepted_at === null` →
`ARTWORK_APPROVED` → "one step left: accept pricing."

## Production

`QueueService::buildJobsForQuote()`:

- **3D lines** — unchanged: each `MODEL_3D` line → its own job; `artwork_refs` =
  `[{ line_item_id, product_name, ref: decal ?? approved proof artwork }]`.
- **UV lines** — one batched job per the order's ready UV lines; `artwork_refs` =
  one entry per line = **that line's approved proof artwork**. Dropped lines are
  excluded.
- Guard: a UV/artwork line reaching the queue with **no approved proof** is a
  gate violation (the order can't be `READY` unless every artwork line is
  approved-or-dropped) — throw, as `buildJobsForQuote` already does for
  unresolved lines (`QueueService.php:55-64`).

Print-file (`ProductionQueueController::printFile`): a job now has multiple files.
Serve them as **per-file signed links** labelled by product/line, plus a
**"Download all" ZIP** named by item (reuse the `exportParts` ZIP pattern,
`AdminCatalogueController`). The floor matches file → item by name.

## Notifications

- **Send proofs** → one batched email. Extend `QuoteReadyMail` to list the round's
  items with per-item composite thumbnails (`ProofCompositeService`, now matched
  **directly by `line_item_id`** rather than by ref-guessing — simplifies
  `matchingProductImage()`).
- **Per-line change request** → staff notification (existing
  `ProofChangesRequestedMail` via `StaffNotifier`), per line.
- **Reminder ladder** (`ReminderSchedule`): `waitingOnProof` = order in `PROOFING`
  with **any** line proof in `SENT` (the existing `proofs.contains(SENT)` check
  still holds since proofs are loaded per order). Wording counts items ("1 item
  still awaiting your approval").
- Staff **notification panel** (`BuyerNotifications`) reports items sent /
  awaiting for the current round.

## API surface

- `POST /quotes/{quote}/lines/{line}/proofs` — stage a `DRAFT` proof for a line
  (artwork ref). Replaces the order-level issue for the per-line case.
- `POST /quotes/{quote}/proofs/send` — flip all `DRAFT` → `SENT`, one email.
- `POST /proofs/{proof}/decide` — unchanged shape (`approve` /`request_changes`
  + notes + attachments), now scoped to one line's proof; delegates order
  transition to `recomputeProofState()`.
- `POST /quotes/{quote}/proofs/approve-all` — approve every `SENT` proof on the
  order in one transaction (buyer "Approve all remaining"; superadmin on-behalf).
- `ProofResource` gains `line_item_id`, `product_name`; `QuoteResource.proofs`
  stays but the client groups by line.

## Testing

Backend (Pest):
- Stage → send transitions `DRAFT` → `SENT` and emails **once** for N lines.
- Per-line approve/request-changes mutate only that line; `recomputeProofState`
  rolls the order up correctly (mixed states → `PROOFING`/`CHANGES_REQUESTED`;
  all approved → `ARTWORK_APPROVED`/`PROOF_APPROVED` per `accepted_at`).
- Drop line voids its proof and unblocks the gate.
- Amend adding a customized line reopens proofing.
- `approve-all` approves only `SENT`, leaves `CHANGES_REQUESTED`.
- `buildJobsForQuote`: UV batched job carries one file per approved UV line
  (labelled); 3D per-line unchanged; unapproved artwork line at queue = throw.
- Print-file serves the labelled set / ZIP.
- Reminder waiting-on-proof fires while any line proof is `SENT`.

Frontend (Vitest):
- Staff: per-line staging, Send enabled/disabled, unsent-DRAFT warning, blocker
  breakdown counts, drop-line.
- Buyer: per-item approve/request-changes, "Approve all remaining" ignores
  in-changes items, progress banner, staggered approval persistence.
- Per-line approved callout + history grouping.

## Files touched (indicative)

Backend: `database/migrations/*` (proofs, production_jobs — reseed), `app/Enums/
ProofState.php`, `app/Models/Proof.php`, `app/Models/LineItem.php`
(`needsProof`), `app/Models/Quote.php` (`recomputeProofState`), `app/Services/
QuoteService.php`, `app/Services/QueueService.php`, `app/Services/
ProofCompositeService.php`, `app/Services/ReminderSchedule.php`,
`app/Http/Controllers/ProofController.php`, `app/Http/Controllers/
ProductionQueueController.php`, `app/Http/Resources/ProofResource.php`,
`app/Http/Resources/QuoteResource.php`, `app/Mail/QuoteReadyMail.php`,
`routes/api.php`, `database/seeders/*`.

Frontend: `src/pages/QuoteDetailPage.tsx`, `src/components/quote/OrderStatus.tsx`,
new `src/components/quote/LineProofRow.tsx` (staff) + `BuyerProofItem.tsx`,
`src/components/quote/BuyerNotifications.tsx`, `src/stores/quoteStore.ts`,
`src/lib/quoteStatus.ts` (DRAFT tone), `src/types.ts`.

## Risks / notes

- **Reshapes the 2026-07-24 order-detail rework**: the approved-artwork callout
  and buyer proof-review become per-line; the merged staff card gains the per-line
  proof rows + blocker breakdown + Send button. Those components are edited, not
  discarded.
- **Floor workflow change**: a UV job now carries several files. Mitigated by
  per-item file labelling + ZIP; no change to job *count* (still one UV job).
- **Approve-all** must be idempotent and transactional to avoid a partial roll-up
  under concurrent buyer clicks / broadcast refetch.
- `ProofState::DRAFT` must be excluded from every "open proof awaiting buyer"
  query (reminders, buyer review) — only `SENT` is awaiting the buyer.
