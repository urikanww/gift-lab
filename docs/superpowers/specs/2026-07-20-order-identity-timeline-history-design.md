# Order Identity, Collapsed Timeline, Status History — Design

**Date:** 2026-07-20
**Status:** Draft for review
**Touches:** `Quote` model, `audit_logs`, `QuoteResource`, `QuoteDetailPage`, `QuoteListPage`, `BuyerDashboardPage`, `ReorderRail`, `CheckoutPage`

## Problem

The order detail header does three things badly.

1. It identifies the order as `Quote #1`. The sequential primary key is the
   buyer's public handle, even though the URL already uses an opaque
   `reference`. Anyone reading `#1` learns how many orders the business has
   ever taken.
2. Its status timeline renders all nine states as one horizontal stepper. At
   desktop width it wraps to two rows and occupies more vertical space than the
   order contents beneath it.
3. There is no history of status changes. A buyer cannot see when their order
   was sent, accepted, or approved — only where it is now.

## Decisions (locked)

| Question | Decision |
|---|---|
| Order identifier, everywhere | `reference` — buyer, staff and admin alike |
| `quote_id` in API payloads | Stays — it is a join key, not a label |
| Timeline shape | Collapsed to current context, full stepper behind a disclosure |
| Status history | Build it — new data, logged at the transition choke point |
| Historical backfill | **None.** New events only, with an explicit "tracking started" note |
| Staff-only ops surfaces | Switch too — one identifier for support |

## Part A — reference as the buyer-facing identity

`Quote #{id}` appears on every buyer surface:

| File | Occurrences |
|---|---|
| `QuoteDetailPage.tsx` | breadcrumb (`:153`), heading (`:164`) |
| `QuoteListPage.tsx` | table row (`:183`), mobile card (`:216`) |
| `BuyerDashboardPage.tsx` | awaiting callout (`:82`), recent list (`:148`) |
| `ReorderRail.tsx` | aria-label (`:57`), card (`:60`) |
| `CheckoutPage.tsx` | success toast (`:210`) |

All become the reference. `QuoteResource` already serialises `reference`, and
every one of these call sites already has it in scope — each builds an
`/orders/{reference}` link. No API change.

Presentation: `Order 9BWVKWCDXH`, not `Quote #9BWVKWCDXH`. The `#` prefix reads
as an ordinal and the reference is not one. "Order" also matches the buyer
vocabulary already used by the `My Orders` nav item, while "Quote" is the
internal domain term.

### Staff and admin switch too

Two identifiers for one order means every support conversation starts by
translating between them — the buyer says `9BWVKWCDXH`, the floor says `#1`,
and someone has to map one to the other before the actual question can be
answered. One identifier on both sides removes that step entirely, and it is
worth more than the id's brevity on internal screens.

These payloads carry a quote identity and currently expose only `quote_id`.
Each gains a `quote_reference` **alongside** it:

| Source | Line |
|---|---|
| `ProductionJobResource` | `:23` |
| `LineItemResource` | `:23` |
| `ProofResource` | `:23` |
| `QueueService` (staff dashboard feed) | `:86` |
| `LineItemAwaitingReconfirm` (broadcast) | `:53` |
| `ProductionQueueUpdated` (broadcast) | `:53` |
| `ProofStatusChanged` (broadcast) | `:50` |
| `QuoteStateChanged` (broadcast) | `:49` |

**`quote_id` is not removed.** It is the key the realtime stores use to match
an incoming broadcast against a row already on screen (`queueStore`,
`procurementStore`). Dropping it to force the rename would break that matching
for a cosmetic gain. The rule is narrower than "delete the id": *stop
displaying it, keep joining on it.*

Staff display surfaces to switch:

| File | Line |
|---|---|
| `DashboardPage.tsx` | `:92` |
| `ProductionQueuePage.tsx` | `:312` |
| `ProcurementPage.tsx` | `:104` |

Also `resources/views/mail/quote-ready.blade.php:78`, which falls back to
`$quote->id` when `tracking_code` is null — that fallback becomes `reference`.

Internal non-display uses of `quote_id` stay untouched: Stripe session
metadata, `ShippingAddressController`, `AmendQuoteRequest` validation. They are
keys, never shown.

**Eager-loading:** `ProductionJobResource` and `ProofResource` reach the
reference through the `quote` relation. Every controller building those
collections must eager-load it or the queue page fires one query per job. This
is the most likely defect in Part A and needs an explicit N+1 check, not a
visual one.

## Part B — collapsed timeline

Nine `QuoteState` values render as one stepper (`QuoteDetailPage.tsx:18`).

Replace with a summary line plus an on-demand full view:

- **Collapsed (default):** current state, the next state, and `Step 4 of 9`.
- **Expanded:** today's full stepper, unchanged.
- A `<button>` toggles, labelled "Show all steps" / "Hide all steps", with
  `aria-expanded`.

Off-path states (`CHANGES_REQUESTED`, `CLOSED`, `CANCELLED`) already fall
through `timelineIndex()` to an on-path index. The collapsed view must not
claim a "next" step for a terminal or diverted state — those render the state
alone with no `→ next`.

Not chosen: grouping into phases (buyers lose the exact stage name, which is
the one thing the widget exists to tell them) and a vertical list (taller, and
the page was just tightened).

## Part C — order status history

### The data does not exist

`audit_logs` is built, working, and never wired to quote transitions:

```
audit rows for quote 1: 0
distinct events in table: pricing_config.updated, product.blockers_resolved,
                          product.gate_deleted, product.updated
```

`Quote::transitionTo()` persists state and logs nothing
(`app/Models/Quote.php:217`):

```php
$this->state = $target;
$this->save();
```

Every transition in `QuoteService` routes through this one method, so a single
insertion point covers all of them.

### Logging

Write an `audit_logs` row inside `transitionTo()` after the guard passes:
`auditable` = the quote, `event` = `quote.state_changed`, `old_values` =
`{state: <from>}`, `new_values` = `{state: <to>}`. `AuditLogger` already
resolves the acting user and IP.

Logging belongs inside `transitionTo` rather than in each `QuoteService` call
site: the guard and the write are already atomic there, and a caller that
forgets to log is a silent hole in an audit trail whose whole value is being
complete.

### Reading

`GET /api/quotes/{quote}/history`, authorised by the existing `view` policy so
a buyer sees only their own. Returns `{from, to, changed_at, actor_name}` per
row, oldest first. Deliberately **not** folded into `QuoteResource`: the detail
payload is already large, and history is wanted on one page.

`actor_name` is the user's name or `null` for system transitions. It is not the
email — an order history is visible to a buyer and staff addresses are not
theirs to have.

### The retroactive gap

Nothing was ever logged, so **existing orders have no history and cannot get
one.** From `created_at`, `accepted_at` and `price_snapshot_at` a partial trail
could be reconstructed, but a timeline that shows two entries and looks
complete is worse than one that admits it is empty. It would silently misdate
every other transition.

So: no backfill. When a quote has no history rows, the section renders

> Status tracking started on {date}. Changes before then were not recorded.

using the date the migration ran, held as a config value.

### UI

A `Status history` card on `QuoteDetailPage`, below the timeline: a vertical
list of `{state} · {date} · {actor}`, newest first, empty state as above.

## Risks

**Old ids become unlookupable — OPEN QUESTION.** A buyer or staff member
holding `#1` from an existing email, invoice or conversation currently finds
that order by reading `Quote #1` off a list. After this change nothing displays
the id.

`QuoteController` has **no search or filter of any kind** — not by reference,
not by id. So there is no lookup to widen; there is nowhere to type either
identifier. Anyone holding an old `#1` is left scanning the list by date.

Three ways out, none of them chosen yet:

1. **Staff-only secondary line.** `Order 9BWVKWCDXH` as the heading with a
   muted `internal #1` beneath it on `QuoteDetailPage`, staff only. Cheapest,
   but partially reintroduces the second identifier this change exists to
   remove.
2. **Add quote search accepting both.** Solves it properly and is useful
   regardless, but it is a new feature and grows this change.
3. **Accept the gap.** Defensible only if old ids are not circulating outside
   the system — you would know that better than I do.

This needs a decision before Part A ships, not after.

**Widening eight payloads risks an N+1.** See the eager-loading note in Part A.
The queue page renders many jobs at once and is the likeliest place to regress.

**The history endpoint is a new authorisation surface.** It must reuse the
`view` policy rather than checking `company_id` inline; a bespoke check on a
new route is how cross-tenant leaks happen.

**Part C is schema plus API plus UI.** It should land after A and B, which are
UI-only and independently shippable.

## Testing

Part A: assert each surface renders the reference and **not** `#{id}` — a
regex on `/#\d+/` catches a partial migration. Backend: each widened resource
includes `quote_reference` and still includes `quote_id`. An N+1 assertion on
the production-queue index (query count stays flat as job count grows) guards
the eager-loading risk, since nothing visual will reveal it.

Part B: collapsed by default; toggling reveals all nine; a terminal state shows
no "next"; `aria-expanded` tracks state.

Part C: `transitionTo` writes exactly one audit row with correct from/to; a
rejected transition writes none; the endpoint refuses another company's quote
(403); the page renders the empty-state note when there are no rows.

## Rollback

Part A and B are UI-only — revert the commit.

Part C's logging is additive and append-only; reverting the reader leaves rows
accumulating harmlessly for whenever it returns.
