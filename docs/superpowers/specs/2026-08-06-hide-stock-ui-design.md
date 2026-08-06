# Hide Stock UI (Buy-Per-Order) — Design

**Date:** 2026-08-06
**Status:** Draft for review

## Problem

The business is buy-per-order: every product is `MAKE_TO_ORDER`, so variant
stock is always 0 and drives nothing.

- Ordering: no stock check at checkout; the procurement engine that consumed
  stock was removed (#28).
- Storefront availability (`ProductResource::availabilityStatus`) short-circuits
  to "made to order" for `MAKE_TO_ORDER` and never reads stock.
- The admin stock inputs/sort/quick-view row therefore display and set a number
  (always 0) that has no effect for this catalogue.

Hide the stock UI so staff aren't entering a meaningless field.

## Decision

Frontend-only. **Keep** the `stock_on_hand` column, the ledger endpoints, and
`availabilityStatus` untouched — dormant support for a genuinely STOCKED product
survives; it's just not shown. No backend, no DB change.

## Changes (4 surfaces)

All in the product-admin UI.

1. **Variant row** (`ProductAdminDetailPage.tsx` `VariantRow`) — remove the
   Stock input. Keep the price-delta input. The row's save posts `price_delta`
   only (stock omitted → backend leaves it unchanged; `updateVariant` treats
   `stock_on_hand` as `sometimes`).
2. **Single-add form** (`VariantsSection`) — remove the Stock field; submit
   `stock_on_hand: 0` in the POST (backend `storeVariant` still requires the
   key). The matrix bulk-add already sends stock 0.
3. **List sort** (`ProductAdminPage.tsx`) — drop `'stock'` from `SortKey` /
   `SORT_KEYS` and remove the `<option value="stock">Stock</option>`.
4. **Product quick-view** (`ProductQuickView.tsx:134`) — remove the
   "In stock" row.

## Untouched (verified)

- `AdminProductController` (`storeVariant` still requires + accepts
  `stock_on_hand`; `updateVariant` still accepts it), the ledger, the
  `withSum('variants','stock_on_hand')` in the index query, `availabilityStatus`,
  `VariantResource` fields. The DB column stays.

## Consequence (accepted)

Staff can no longer set a variant's stock from the UI. That's intended for a
buy-per-order shop. Re-selling a truly STOCKED product later would need the
stock input re-exposed (or a DB set) — out of scope.

## Testing

Frontend (Vitest):
- `VariantRow` no longer renders a Stock input; save posts `price_delta`
  (no `stock_on_hand`). Archive + delta editing (from #31) still work.
- `VariantsSection` single-add posts `stock_on_hand: 0`.
- `ProductAdminPage` sort dropdown has no "Stock" option.
- `ProductQuickView` renders no "In stock" row.
- Full frontend suite green; fix any test asserting the removed controls.

## Out of scope

- Removing the DB column / ledger / backend stock handling.
- Any change to `availabilityStatus` or storefront display.
- Re-exposing stock for STOCKED products (a future toggle if ever needed).
