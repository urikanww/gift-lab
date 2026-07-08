# Superadmin price override - design

**Date:** 2026-07-07
**Status:** Approved

## Problem

Product sell price is computed dynamically by `PricingService` (landed cost +
margin + print fee + bulk discount). A superadmin needs to pin an exact per-unit
price on a product, overriding that computation. The override covers the product
price only - delivery is still charged dynamically on shipment weight.

## Semantics

- New nullable column `price_override` (DECIMAL 10,2) on `products`.
  `null` = dynamic pricing (unchanged behaviour).
- When set, `price_override` is the **absolute per-unit base price**:
  - Replaces landed + margin + print entirely.
  - **Bulk-qty discount is skipped.**
  - **Variant `price_delta` still adds on top**: `unit_price = price_override + delta`.
- Applies **everywhere** - `PricingService::unitPrice()` is the single chokepoint,
  so quotes, public estimates, storefront and admin all inherit it.
- May go **below landed cost** - no margin-floor guard. The UI warns when below the
  computed landed cost but still allows saving. It is a deliberate superadmin override.
- Delivery fee is untouched (still `deliveryFor(chargeableWeight)`).

## Backend changes

1. **Migration** - add nullable `price_override` DECIMAL(10,2) to `products`.
2. **`Product` model** - add `price_override` to `$fillable`; cast `decimal:2`.
3. **`PricingService::unitPriceBreakdown()`** - early branch: when
   `price_override !== null`, return a breakdown whose `unit_price = override + delta`,
   with derived components (margin, print_per_unit, bulk_discount) zeroed, plus a
   `price_override` value and `overridden => true` flag for the staff tester.
   `landedCost()` is still computed and returned for reference.
4. **`AdminProductController::update()`** - accept `price_override`
   (`nullable|numeric|min:0`) **only when `$request->user()->isSuperadmin()`**;
   a `staff_admin` sending the field has it stripped (silently ignored, matching how
   other optional fields degrade - not a 403). Empty/`null` clears the override.
   Audit-log old→new `price_override`.
5. **`serialize()`** - expose `price_override`; `selling_price` now reflects the
   override automatically.

## Frontend changes

- `frontend/src/types.ts` - add `price_override: number | null` to `AdminProduct`.
- `ProductAdminDetailPage.tsx` `EditForm` - a **superadmin-only** `Input`
  "Price override (SGD)" (gated on `useAuth().user.role === 'superadmin'`).
  Blank clears it. Helper text explains it overrides dynamic pricing and excludes
  delivery. Danger note shown when the entered value is below the product's landed cost.

## Testing

- **PricingService unit** - override replaces base; variant delta adds; bulk skipped;
  below-cost allowed; null = dynamic path unchanged.
- **Controller feature** - superadmin sets + clears override (with audit entry);
  `staff_admin`'s `price_override` field is ignored.
- **Quote flow** - a quote on an overridden product uses the overridden unit price.

## Decisions

- staff_admin sending `price_override` → silently ignored (not rejected).
- Both MODEL_3D and CORE products are overridable (class-agnostic).
