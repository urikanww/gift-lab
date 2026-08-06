# Unplug & Hide Legacy Procurement/Inventory — Design

**Date:** 2026-08-06
**Status:** Draft for review
**Rides on:** PR #26 (branch `feat/buy-list-and-catalogue-gate`)

## Problem

The new manual **Buy list** (PR #26) now owns buying for approved orders. Three
pieces of the old world are now redundant or misleading:

1. **Old restock menu** — the supplier-reorder page (`/reorders`, nav label
   "Buy-list") only ever fills from auto-drafted reorders, which the new flow no
   longer creates. It will sit empty.
2. **Menu naming clash** — the nav shows **"Procurement"** for the new buy list
   *and* **"Buy-list"** for the old restock page. Backwards and confusing.
3. **Filament counting** — the app seeds and decrements filament stock, but the
   business tracks filament by hand. Seeded filament is a number nobody
   maintains.

## Decision: unplug & hide, do not rip out

Chosen approach: turn these off and take them off-screen, but leave all
underlying code, models, tables, refund-on-cancel logic, and the card-payment
path **dormant and intact**. Nothing destructive, nothing touched on the live
database. Reversible by re-adding a few lines.

Explicitly **out of scope** (left dormant): `ProcurementManager` + strategies,
`QuoteService::procure/reconfirmLine/confirmStock`, the `/procure`,
`/confirm-stock`, `/line-items/{id}/reconfirm`, and supplier-reorder backend
routes, `PaymentService`'s auto-procure on B2C capture, the `filaments` and
`supplier_reorders` tables, and the inventory refund-on-cancel logic. A future
"rip out" is a separate, planned job.

## Changes

Three small, reversible edits.

1. **Hide the old restock menu** (`frontend/src/components/StaffLayout.tsx:38`,
   `frontend/src/App.tsx:38,195`)
   - Remove the nav item `{ to: '/reorders', label: 'Buy-list', ... }`.
   - Remove the `<Route path="reorders" ...>` and the `ReorderBuyListPage` lazy
     import.
   - Leave `ReorderBuyListPage.tsx`, `AdminReorderController`, `SupplierReorder`,
     and the `/admin/supplier-reorders` routes in place (dormant, unreachable
     from the UI).

2. **Rename the new buy-list menu** (`StaffLayout.tsx:37`)
   - Change the `/procurement` nav item label from **"Procurement"** to
     **"Buy list"**, matching the page's own `<h1>Buy list</h1>`.
   - Remove its `badge: q?.procurementToReconfirm` — that counter is the old
     awaiting-reconfirm count, which no longer reflects the buy list and would
     mislead. (A real "lines to buy" badge is a possible future add, not now.)

3. **Stop stocking filament** (`database/seeders/DatabaseSeeder.php:24`)
   - Remove `FilamentSeeder::class` from the root seeder run.
   - Leave `FilamentSeeder`, the `Filament` model, and the `filaments` table in
     place (dormant). The decrement/draft code stays but is only reachable via
     the already-UI-dead `procure()` path, so no new filament reorders arise in
     normal use.

## Testing

- **Staff nav test** (`StaffLayout` test, if present): assert the menu shows a
  "Buy list" item pointing at `/procurement`, and no "Buy-list" item pointing at
  `/reorders`. Update any assertion that expected the old labels.
- **Route smoke** (if App-level routing is tested): `/reorders` no longer
  resolves to a page. If no such test exists, none is added — the nav removal is
  the user-visible guarantee.
- No backend tests change: no backend behaviour is removed, only frontend
  reachability and one seeder line. Existing suites (which seed filament
  explicitly where needed) are unaffected because they call `FilamentSeeder` or
  factories directly, not the root `DatabaseSeeder` filament line.

## Verification

- After the change, the staff menu reads: Dashboard, Quotes, Production,
  **Buy list**, Products, … — with no "Procurement" and no "Buy-list" (reorders).
- `/reorders` is unreachable from the UI; visiting it directly is out of scope
  (route removed → app's not-found handling applies).

## Follow-ups (not this change)

- Optional "lines to buy" badge on the Buy list nav item.
- The full "rip out" (delete tables, strategies, refund logic, rewire payment) —
  its own spec when the dormant path is confirmed truly unused.
- **B2C pay-now vs the buy list — RESOLVED (branch `fix/b2c-paynow-buylist-gate`).**
  `PaymentService::confirmPaid` previously auto-called `QuoteService::procure()`
  on card capture, stranding the order in `PROCURING` with no `stock_confirmed_at`
  (invisible to both the buy list and the floor). Now it calls
  `QuoteService::markQuoteBought()` — the same gate as a manual buy — so a
  card-paid order is billed once and reaches `READY` in one step. Covered by
  `PayNowTest`.
- **Reconfirm remnants (now fully dormant).** With the reconfirm desk replaced by
  the buy list and the payment path rerouted, `QuoteService::procure()` /
  `reconfirmLine()` have **no live caller** — the only producers of
  `AWAITING_RECONFIRM` are the UI-less `/procure` route and the off-by-default
  `block_on_qty_short` config. The `/procurement/awaiting-reconfirm` +
  `/line-items/{id}/reconfirm` routes/store methods and that state have no UI;
  the dashboard's dead "Procurement to reconfirm" tile was removed. Fold into the
  future "rip out" if `procure()` is ever deleted.
- Live-DB minimum-order-quantity audit (data check, run by an admin):
  `Product::where('min_order_qty','>',1)->count()`.
