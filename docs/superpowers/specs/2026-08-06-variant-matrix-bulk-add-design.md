# Variant Matrix Bulk-Add — Design

**Date:** 2026-08-06
**Status:** Draft for review

## Problem

Adding variants to a blank is one-at-a-time: the Variants section takes a single
free-text option + stock + price-delta and POSTs one variant per submit
(`ProductAdminDetailPage.tsx` `VariantsSection`, `AdminProductController::storeVariant`).
A blank that varies by size × colour (e.g. 3 × 2 = 6) means six separate
form-fills and round-trips. The Shopee affiliate feed cannot supply variants
(name/price/image/link only), so the variant set is always staff-entered — the
lever for speed is bulk entry, not an API pull.

## Decision (settled in brainstorming)

- **Combined label, not structured axes.** Generated combos are stored in the
  existing shape `attributes: {option: "M / Black"}`. Axes are an entry-time
  convenience only — nothing downstream (quote lines, pricing, display) changes.
- **N axes** (not capped at 2), cross-producted; **200-combo cap** guards a
  runaway cross-product.
- Generated variants are created at **stock 0** (buy-per-order); staff refine
  stock/SKU per-row afterward via the existing controls.
- **Dedup by skip:** a combo whose `option` label already exists on the product
  (case-insensitive) is skipped, so re-running never duplicates.
- One shared **price delta** applied to every generated combo.
- The existing single-add form stays for one-off variants.

## Backend

### New endpoint
`POST /admin/products/{product}/variants/bulk` — staff, `permission:products.edit`
(mirrors `storeVariant`'s route/middleware in `routes/api.php`).

Request:
```json
{ "variants": [ { "option": "S / Black", "price_delta": 0 }, … ] }
```
The **frontend** builds the combo list (labels + shared delta); the backend just
creates them.

Validation (`StoreVariantsBulkRequest`, new Form Request):
- `variants` — required array, `min:1`, `max:200`.
- `variants.*.option` — required string, `max:100`.
- `variants.*.price_delta` — nullable numeric.

Controller (`AdminProductController::storeVariantsBulk`):
- `abort_unless($request->user()->isStaff(), 403)`.
- Load the product's existing option labels once; lower-case them into a set.
- In one `DB::transaction`: for each requested variant whose lower-cased `option`
  is **not** already present (and not a duplicate within the request), create a
  `Variant` (product_id, `attributes: {option}`, sku null, stock_on_hand 0,
  reorder_threshold 0, price_delta, currency SGD). Stock is 0, so no ledger
  `Init` movement is written (matches `storeVariant`, which only records the
  ledger when opening stock > 0).
- Audit one `variant.bulk_created` row with the product id + created/skipped
  counts.
- Return `{ "data": { "created": N, "skipped": M } }` (201).

### Reuse
Extract the single-variant build in `storeVariant` into a private
`makeVariant(Product $product, array $attrs): Variant` (attributes, sku,
reorder_threshold, price_delta, stock 0) so `storeVariant` and
`storeVariantsBulk` share one creation path; `storeVariant` keeps its ledger
`Init` when opening stock > 0.

## Frontend

`VariantsSection` in `frontend/src/pages/ProductAdminDetailPage.tsx` gains a
"bulk add (matrix)" panel above the existing single-add form.

State:
- `axes: { name: string; values: string }[]` — starts with two rows
  (`[{name:'Size',values:''},{name:'Colour',values:''}]`); add/remove rows.
- `bulkDelta: string` (default `'0'`).

Combo generation (pure helper, unit-testable — put in
`frontend/src/lib/variantMatrix.ts`):
- For each axis, split `values` on comma, trim, drop blanks, de-dupe (case-insensitive) preserving order.
- Drop axes with no values.
- Cross-product the remaining axes; join each combination's values with `" / "`.
- A single axis yields bare values (no `" / "`).
- Return the ordered, de-duped label list.

UI:
- One row per axis: `name` input (label-only, e.g. "Size") + comma-separated
  `values` input + remove-axis button; an "add axis" button below.
- A shared "price delta (all)" input.
- **Live preview** box: "will create N variants" + the labels as pills. Recomputed
  from state on every keystroke (no server call).
- "create N variants" button (disabled at N = 0 or N > 200): POSTs
  `{ variants: labels.map(o => ({ option: o, price_delta: Number(bulkDelta) })) }`
  to the bulk endpoint, then toasts `Created N (M skipped, already existed)`,
  resets the axes, and calls `onChanged()`.
- The existing single-add form and per-row `VariantRow` edit are unchanged.

`frontend/src/lib/api` types: add a `bulkAddVariants` call or inline the POST in
`VariantsSection` (match the file's existing inline-`api.post` style).

## Testing

Backend (Pest):
- Bulk-creates N variants with the given labels, all at stock 0, no
  `StockMovement` rows written.
- Skips labels that already exist on the product (case-insensitive); returns the
  right created/skipped counts; re-running the same request creates nothing.
- De-dupes duplicate labels within one request.
- Rejects empty `variants` and > 200; `option` required/max-100.
- Permission-gated (403 without `products.edit`).

Frontend (Vitest):
- `variantMatrix.ts`: two axes → correct cross-product order; single axis → bare
  values; blank/whitespace/duplicate values trimmed and de-duped; empty → `[]`.
- `VariantsSection`: typing axis values updates the preview count/labels; the
  create button posts the generated list to `/variants/bulk`; disabled when the
  preview is empty.

## Out of scope

- Structured axis attributes (`{Size, Colour}`) — deliberately not done; combined
  label keeps the change non-invasive.
- Per-combo stock / SKU at generate time (all land at stock 0; refine per-row).
- Any Shopee-side variant pull — not possible from the affiliate feed.
- Bulk stock entry / CSV variants — separate, not needed for the speed win here.
