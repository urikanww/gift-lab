# Stock Management Plan — UV vs 3D

Date: 2026-07-07
Status: Proposed (design only — no code yet)

## Problem

UV/CORE and 3D printing are being squeezed into one `stock_on_hand` counter.
They are not the same kind of inventory, so the single counter is confusing and
wrong for 3D. Current on-hand is also a bare mutable integer: no history, no
reconciliation, race-prone under concurrent orders.

## Mental model

You stock *different things* per track:

| | UV / CORE / SCRAPED_UV | 3D printed (MODEL_3D) |
|---|---|---|
| What sells | blank + print | printed object |
| What you HOLD | the **blank** (discrete units) | **nothing finished** |
| Real constraint | units on shelf | (later) filament grams + printer time |
| `stock_mode` | `STOCKED` | `MAKE_TO_ORDER` |
| Unit | pcs | n/a for now |

- **UV/CORE = unit inventory.** Existing `Variant.stock_on_hand` +
  `reorder_threshold` already fits. Keep it.
- **3D = make-to-order.** Decision (2026-07-07): **no material counting yet.**
  3D products are always makeable; `stock_mode = MAKE_TO_ORDER`; on-hand is not
  tracked. Material (filament grams) inventory is deferred — see Future.

## Decisions locked

1. 3D: `MAKE_TO_ORDER`, no gram counting this round.
2. Adopt an **append-only stock movement ledger**; `stock_on_hand` becomes a
   derived sum, not the source of truth.
3. This session = plan only. No stock code until this doc is reviewed.
4. "On-demand" = **backorder**, modeled as `allow_backorder` bool + derived
   procurement, NOT a third `stock_mode`. UV/CORE only; `AWAITING_STOCK` gate.
   See Backorder section.

## Design: append-only ledger

New table `stock_movements` (mirrors the existing `AuditLogger` append-only
culture):

```
stock_movements
  id            bigint pk
  variant_id    fk -> variants (cascade)         -- unit stock only, for now
  delta         integer            -- +restock, -sale, ±adjust, -scrap
  unit          enum(PCS)          -- room for G/ML later
  reason        enum(SALE | RESTOCK | ADJUST | SCRAP | RETURN | INIT)
  ref_type      string nullable    -- 'order' | 'adjustment' | ...
  ref_id        bigint  nullable
  actor_id      fk -> users nullable   -- null = console/system
  note          string  nullable
  created_at    timestamp
  index (variant_id, created_at)
```

Rules:
- **Never mutate `stock_on_hand` directly.** Every change is a movement row.
- `Variant.stock_on_hand` is kept as a **cached sum** of its movements, updated
  inside the same DB transaction that inserts the movement. (Cache, not truth —
  can be rebuilt from the ledger anytime.)
- Reads stay cheap (read the cached column); audits/reconciliation replay the
  ledger.

### Why ledger over in-place int
- History: "why is the count 3?" is answerable.
- Reconciliation: rebuild `stock_on_hand = SUM(delta)` to catch drift.
- Concurrency: append + transactional sum avoids lost updates from two orders
  hitting the same variant at once.
- Reversibility: a bad adjustment is corrected by a compensating row, not a
  silent overwrite.
- Consistency: same shape works for grams/ml when 3D material lands later.

## Where stock changes originate (wire these to emit movements)

- Buyer order placed / paid → `SALE` (negative) per variant qty.
- Order cancelled / refunded → `RETURN` (positive).
- Staff manual restock → `RESTOCK`.
- Staff correction → `ADJUST`.
- Damaged/lost blank, failed print (later) → `SCRAP`.
- Seed / first count → `INIT`.

Existing staff "adjust variant stock" endpoint (see
`AdminProductController::updateVariant`) must switch from writing
`stock_on_hand` directly to emitting an `ADJUST`/`RESTOCK` movement.

## Fulfillment gate (unified)

- UV/CORE (`STOCKED`), backorder off: `stock_on_hand >= qty`.
- UV/CORE (`STOCKED`), backorder on: always sellable; short qty goes negative
  and is procured (see Backorder).
- 3D (`MAKE_TO_ORDER`): always fulfillable (no stock check this round).

## Backorder / "on-demand" (added 2026-07-07)

Requirement: sell a UV blank even at stock 0; production then procures the blank
from the product's affiliate `source_url` before printing. 3D already behaves
this way via `MAKE_TO_ORDER`.

Modeled as two **independent axes**, NOT a third `stock_mode` value (a third
enum would overlap `MAKE_TO_ORDER` and mean different things per class):

1. **Order-at-0 policy** — new flag `Product.allow_backorder` (bool, default
   false). Only meaningful when `stock_mode = STOCKED`. Decision: applies to
   **UV/CORE only**; 3D stays `MAKE_TO_ORDER` and ignores it.
2. **Procurement method** — *derived, not stored*:
   - `SCRAPED_UV` + `source_url` → production task `PROCURE` (buy blank from
     affiliate source).
   - `MODEL_3D` → production task `PRINT`.

### Ledger handles it
A backorder `SALE` drives `stock_on_hand` **negative**. Negative balance = the
procurement worklist ("owe N blanks, go buy"). Blank arrives → `RESTOCK`
movement pulls it back toward 0.

```
SALE    -1   (on-hand -1)   → order enters AWAITING_STOCK, flagged for procurement
RESTOCK +1   (blank arrives) → order releases into the production queue
```

### Order flow (decision: AWAITING_STOCK gate)
Backordered UV order parks in a new order state `AWAITING_STOCK` until the
procured blank is received (`RESTOCK`), then it enters production. This keeps
lead times honest (UV procure = purchase + delivery time, unlike an instant 3D
print). 3D on-demand skips `AWAITING_STOCK` and goes straight to `PRINT`.

### Build deltas (add to phased build)
- Migration: `products.allow_backorder` bool default false.
- Order placement: if `stock_on_hand - qty < 0` and `allow_backorder` →
  record `SALE`, set order line `AWAITING_STOCK` instead of blocking.
- Procurement view: list variants with negative on-hand (the buy-list) +
  affiliate `source_url` per line.
- Receiving: staff marks blank received → `RESTOCK` movement → auto-release
  parked orders whose on-hand is now covered.
- Admin product form: `allow_backorder` toggle, shown only for STOCKED
  (UV/CORE) products.

## Phased build (for the later implementation session)

1. **Migration** `stock_movements` + `StockMovement` model.
2. **Service** `StockLedger` — `record(variant, delta, reason, ref, actor)` that
   inserts a movement and updates the cached `stock_on_hand` in one transaction.
   One choke point; nothing else touches the column.
3. **Backfill** one `INIT` movement per existing variant = current
   `stock_on_hand`, so the ledger reconciles from day one.
4. **Route existing writes** through `StockLedger` (adjust endpoint, order
   placement, cancellation).
5. **History endpoint** `GET /admin/variants/{variant}/movements` (mirrors the
   product `/history` audit view).
6. **Frontend**: variant stock panel shows current on-hand + movement log;
   restock/adjust form posts a movement.
7. **Reconcile command** `stock:reconcile` — recompute cached sums, report drift.

## Future (deferred, not now)

- 3D material inventory: `materials(kind, material, color, grams_on_hand,
  reorder_threshold_grams)`; 3D orders draw down `Product.est_grams` via `G`
  movements; failed print = `SCRAP` grams (real cost capture).
- Printer capacity / `est_print_minutes` as a scheduling constraint.

## Out of scope

- Product rename + rename audit trail — already shipped 2026-07-07
  (`AdminProductController::update` now logs `name` before/after).
