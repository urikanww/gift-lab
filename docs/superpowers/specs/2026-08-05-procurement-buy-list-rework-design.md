# Procurement Buy-List Rework — Design

**Date:** 2026-08-05
**Status:** Draft for review

## Problem

Today the Procurement menu is an **exceptions desk**: it only shows lines that
failed an automatic re-check (price jumped, quantity short). The actual buying
of blanks is invisible to the app — procurement runs automatically at the
`CONFIRMED` state, silently re-checks/decrements, and pushes the order straight
to the production floor. Staff who physically buy blanks from Shopee (or print
3D parts in-house) have no worklist telling them *what to buy for which order*.

The business runs **buy-per-order with zero held stock** (see
`2026-07-12-uv-blank-library-design.md`). So the automatic, stock-aware
procurement engine is the wrong shape. Staff need a plain shopping list.

## Business context (decided earlier, recorded here)

- No inventory held. Every blank is bought after the order lands.
- 3D items are printed in-house; **filament is tracked manually, off-app**. The
  app must not block on, decrement, or reorder filament.
- "Artwork approved" in this app means `PROOF_APPROVED` (design **and** price
  both agreed). `ARTWORK_APPROVED` (design only, price still open) does **not**
  trigger the list — buying before the price is agreed is the risk we avoid.

## What we're building

Replace the exceptions desk with a **manual buy list**.

### The list

- One menu. Shows every line item from orders at `PROOF_APPROVED` or later whose
  line is still un-bought (`Pending`/`Amended`), that has not yet reached the
  production floor.
- **Two views**, staff-toggled:
  - **By product** — every order needing the same product grouped together, for
    bulk buying (e.g. 20 mugs across 5 orders on one row-group).
  - **By order** — everything a single order needs.
- Each row shows product, quantity, and the order reference(s) it belongs to.
- **Buy link button** per row:
  - Marketplace (SCRAPED_UV) → the product's affiliate/primary source link
    (`affiliate_url`, else `source_url`).
  - 3D (MODEL_3D) → the model's source page (`source_url`).
  - Link data already exists on `Product` — no new fields.
- **No live price-checking and no price-warning flag.** Explicitly dropped to
  keep the page cheap to load. Staff see the real price when they open the buy
  link.

### Marking bought

- **By product view:** a "Mark all bought" action clears that product across
  every listed order at once.
- **By order view:** tick each line individually.
- Marking a line bought is the **single action** that moves work forward. It:
  1. Raises the order's bill in the background if not already raised
     (auto-issues invoice → drives `INVOICED` → `CONFIRMED` → `PROCURING`),
     using the order reference as the PO reference. Staff never touch an invoice
     button.
  2. Advances the line to `Ready` and builds its production job — **without**
     any stock decrement, marketplace re-check, or filament draw. (This bypasses
     the old per-class procurement strategies entirely.)
  3. When the last line on an order is marked bought, the order rolls to `READY`
     (on the production floor) and leaves the buy list.

### One-push flow (corrected)

There is **exactly one** push to production, and it is the "Bought" click. The
earlier idea of a separate "Approve & send to production" button is **dropped** —
its job is absorbed here.

```
Design + price approved
        │  (automatic — no button)
        ▼
Item appears on the buy list
        │  staff physically buy / print
        ▼
Staff click "Bought"
        │  raises bill  +  builds job  +  advances line
        ▼
Item on production floor, off the buy list
```

### Accepted risk (recorded)

Because items appear at approval, staff *can* physically buy before the bill
exists in the system. The bill auto-raises at the "Bought" click, so nothing is
ever **produced** unbilled — but a customer who vanishes between approving and
staff buying is money already spent. The owner accepts this trade for the speed.

## Architecture

No new order-state edges are needed — the existing state machine already allows
`PROOF_APPROVED → INVOICED → CONFIRMED → PROCURING → READY`
(`QuoteState.php:60-63`). The rework drives those existing edges from a new
trigger instead of the old automatic one.

Units:

1. **Buy-list read model** (new query/service) — assembles the list from
   `Pending`/`Amended` lines on `PROOF_APPROVED`+ orders, in both groupings.
   Read-only; no side effects.
2. **`GET` buy-list endpoint + resource** — feeds the page. Replaces the current
   broadcast-only `ProcurementPage` data path (the page today has no initial
   fetch at all — a known bug in `ORDER_WORKFLOW.md`).
3. **"Mark bought" action** (new controller + service method) — per line or per
   product-group. Reuses `QuoteService` invoice/confirm and the line
   `Purchased → Inbound → Received → Ready` transitions and job-build, but skips
   the `ProcurementManager` strategies (no stock/marketplace/filament effects).
4. **Frontend `ProcurementPage` rework** — grouped list, view toggle, buy-link
   buttons, mark-bought controls. Reuses the existing `ListFilters` pattern.

### Removed

- The automatic "Run procurement" step (`CONFIRMED → PROCURING` auto-advance)
  and its per-class stock/marketplace/filament effects, for this flow.
- The separate "Issue invoice" click on the order page (folded into "Bought").
- The price-jump / qty-short reconfirm desk UI and its live re-check.

> Note: the `ProcurementManager` / strategy classes and their tests are left in
> place but no longer called by the main flow. Removing them is a separate
> cleanup, out of scope here.

## Testing

- Read model returns the right lines in both groupings; excludes
  `ARTWORK_APPROVED`, already-bought, and floor orders.
- "Mark bought" on a single line: bill raised once, job built, line `Ready`, no
  stock/filament movement recorded.
- "Mark all bought" for a product: clears every listed order's line for that
  product; each order's bill raised once.
- Last line of an order marked → order reaches `READY`, drops off the list.
- Idempotency: re-clicking "Bought" on an already-bought line is a no-op, not a
  double bill.

## Out of scope (follow-ups)

- Removing the now-empty Buy-list (supplier reorder) menu — provably empty once
  filament tracking is off, but a separate change.
- Deleting the dormant `ProcurementManager` strategy code.
- Catalogue validation / stock-mode default changes (tracked separately — see
  the validation audit accompanying this spec).
