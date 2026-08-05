# Catalogue Publish-Gate: Staff-Clearable Blockers + Buy-Per-Order Defaults — Design

**Date:** 2026-08-05
**Status:** Draft for review

## Problem

The business holds no stock — every blank is bought per order. Two things in the
catalogue publish gate don't fit that model, and one publish blocker is a
staff dead-end:

1. **`stock_unreadable`** — a completeness-gate reason that fires when a
   `STOCKED` product has no readable stock estimate (`CompletenessGate.php:44`).
   The business never uses `STOCKED` products, so this reason is dead weight.
2. **The `stock_mode` column defaults to `STOCKED`** at the database level
   (`2026_07_01_000007_create_products_table.php:44`). Import services override
   it to `MAKE_TO_ORDER`, so real products are safe — but any product created
   another way inherits a stock-aware mode it shouldn't have.
3. **`not_printable` cannot be cleared from the UI.** The reason fires when
   `! is_printable || print_method === null` (`CompletenessGate.php:36`). Staff
   can set `print_method` in the edit form, but there is **no control for
   `is_printable`** (`ProductAdminDetailPage.tsx:422-489`). A product imported
   with the flag off is permanently unpublishable through the UI.

## Non-goals

- We are **not** removing the `not_printable` gate. An unprintable product is
  unproducible in this business; the guard is correct. We are removing the
  *dead-end*, not the check.
- We are **not** removing the `stock_mode` concept — only changing its default
  and dropping the one gate that depends on the unused `STOCKED` value.

## Decisions

| Blocker / field | Decision |
|---|---|
| `stock_unreadable` | **Remove** from `CompletenessGate`. Inert for buy-per-order; the business has no `STOCKED` products. |
| `not_printable` | **Keep the gate.** Add an `is_printable` toggle to the product edit form so staff can clear it. |
| `stock_mode` default | **Flip** the column default from `STOCKED` to `MAKE_TO_ORDER`. |
| `min_order_qty` default | Already `1` — no change. (Optional one-off audit for products set higher.) |
| `missing_price`, `missing_dimensions`, `source_dead` | No change — already staff-editable. |

The user confirmed non-printable resale items are rare-but-possible, so the flag
stays (rather than being dropped and always-derived) and gains a toggle.

## Changes

1. **`CompletenessGate::reasons()`** — delete the `stock_unreadable` branch and
   update the class docblock's reason list. `not_printable` and the rest are
   untouched.
2. **Products table migration** (new migration, not an edit to the original) —
   change `stock_mode` default to `MAKE_TO_ORDER`. Existing rows unaffected
   (they already carry an explicit value from the import services).
3. **`ProductAdminDetailPage` `EditForm`** — add an `is_printable` toggle:
   a `useState` seeded from `product.is_printable`, rendered as a checkbox/switch
   near `print_method`, and included in the save payload. The backend already
   accepts `is_printable` (`AdminProductController` `PRODUCT_RULES`), so no API
   change is needed.

## Testing

- `CompletenessGate` no longer returns `stock_unreadable` for a `STOCKED`
  product with a null estimate (and never did for `MAKE_TO_ORDER`).
- A product with the printable flag off, edited via the form with the toggle on
  + a print method set, clears `not_printable` and can publish.
- A newly created product (no explicit `stock_mode`) defaults to
  `MAKE_TO_ORDER`.
- Existing `not_printable`/other gates still fire when their fields are missing.

## Out of scope

- The Procurement buy-list rework (its own spec,
  `2026-08-05-procurement-buy-list-rework-design.md`).
- Removing the `stock_mode` column or the `STOCKED` enum value entirely — a
  larger change only worth doing if resale-with-stock is ruled out for good.
