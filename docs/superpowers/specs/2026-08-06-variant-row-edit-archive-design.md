# Variant Row: Editable Delta + Archive — Design

**Date:** 2026-08-06
**Status:** Draft for review

## Problem

Once a variant is added, staff can only change its **stock** from the row UI —
they cannot edit its **price delta** and cannot **remove** it.

- Price delta: the backend `PATCH /admin/variants/{variant}` already accepts
  `price_delta`; the row just renders it as read-only text and its save sends
  only `stock_on_hand` (`ProductAdminDetailPage.tsx` `VariantRow`). Pure UI gap.
- Delete: there is no delete endpoint or button at all. Hard delete is unsafe by
  design — variants are referenced by historical `line_items.variant_id`, so
  removing one would orphan past orders. `Variant` already uses `SoftDeletes`,
  but nothing exposes a per-variant archive (only archiving the whole product
  cascades).

## Decisions

- **Editable price delta:** add a delta input to the row; one save sends stock
  **and** delta to the existing PATCH. No backend change for this part.
- **Archive = soft delete:** new `DELETE /admin/variants/{variant}` →
  `variant->delete()`. Past orders keep their `variant_id` (resolved
  `withTrashed`); the variant leaves the product's variant list and can't be
  ordered again. Confirmed before firing (no un-archive UI, so effectively
  one-way for staff).
- **No last-variant guard:** archiving the only variant of a CORE product just
  makes it "not orderable" (matches existing variantless-CORE behaviour).
  SCRAPED_UV/3D don't require variants.
- **No restore UI** — soft-deleted variants remain recoverable in the DB, but a
  restore screen is out of scope.

## Backend

- **Route:** `DELETE /admin/variants/{variant}` (staff, `permission:products.edit`),
  next to the existing variant routes in `routes/api.php`.
- **`AdminProductController::archiveVariant(Variant $variant): JsonResponse`:**
  `abort_unless($request->user()->isStaff(), 403)`; `$variant->delete()`
  (soft); audit `variant.archived` with product_id + the variant label; return
  `{ "data": { "archived": true } }` (200).
- `updateVariant` is unchanged — it already accepts `price_delta`.

## Frontend (`VariantsSection` / `VariantRow`)

- `VariantRow` gains a **Price delta** input (seeded from `variant.price_delta`)
  beside Stock, and an **Archive** button.
- The row's save posts `{ stock_on_hand, price_delta }` in one PATCH (extend the
  section's `updateStock` into an `updateVariant(variant, {stock, delta})`, or
  add a delta to the existing call). Stock unchanged still skips the ledger
  (backend only records an ADJUST when the target differs).
- **Archive:** button → a lightweight confirm ("Archive this variant? Past
  orders keep it; it can't be ordered again.") → `DELETE`; on success the row
  drops out (refetch via `onChanged`) + toast.
- Add `variant.archived` to the product-history event labels
  (`HISTORY_EVENT_LABELS`).

## Testing

Backend (Pest):
- `archiveVariant` soft-deletes: the variant is gone from
  `product->variants()` but present `withTrashed`; a `line_items.variant_id`
  pointing at it still resolves. Audit row written. 403 for non-staff.
- `updateVariant` already-tested for stock; add a case that a PATCH with
  `price_delta` updates it (no ledger movement when stock unchanged).

Frontend (Vitest):
- `VariantRow` renders a delta input seeded from the variant; save sends both
  `stock_on_hand` and `price_delta`.
- Archive button calls `DELETE /admin/variants/{id}` after confirm; row leaves
  the list on success.

## Out of scope

- Restore/un-archive UI.
- Bulk edit / bulk archive.
- Any change to how deltas price a line (unchanged: `base_cost + price_delta`).
