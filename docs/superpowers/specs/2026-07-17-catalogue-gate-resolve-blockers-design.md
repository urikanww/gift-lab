# Catalogue Gate — Resolve Blockers Inline — Design

**Date:** 2026-07-17
**Status:** Draft for review
**Surface:** `CatalogueAdminPage` (route `/catalogue-admin`), `AdminCatalogueController`,
`ScrapedCatalogueService`

## Problem

A `SCRAPED_UV` product sitting at `CANNOT_PUBLISH` because the scraper couldn't
read its weight is a dead end in the UI. The gate renders `cannot_publish_reasons`
as read-only `Badge`s (`CatalogueAdminPage.tsx:694-704`) and, for any row without
inline tools, prints an explicit surrender (`:735-741`):

> "Fix the blockers at the source - re-checked on next sync."

That copy is honest about today's behaviour and wrong about the need. The three
most common scraped blockers — missing dimensions/weight, no print method, no
price — are facts a staff member can read off the listing in five seconds. There
is no reason to wait for a sync, and no reason to make them leave the gate for a
product detail page to type four numbers.

`MODEL_3D` rows already have inline fixers (`Model3dRowTools`,
`CatalogueAdminPage.tsx:115-207`; `verify-estimates`, `model-file`, `print-zone`
endpoints). `SCRAPED_UV` has none. This closes that gap.

### The dependency that makes this non-trivial

`ScrapedCatalogueService::evaluateAndSetState()` is **private**
(`ScrapedCatalogueService.php:155`) and reachable only from `ingest()` / `resync()`
— i.e. only from a scraper fetch. `Model3dCatalogueService::regate()` (`:423-444`)
is public and is exactly this method's counterpart.

Consequence today: `PATCH` the missing weight onto a `CANNOT_PUBLISH` scraped
product and it **stays `CANNOT_PUBLISH` with stale `cannot_publish_reasons`**;
`publish` then 422s at `AdminCatalogueController.php:171` because the state isn't
`READY_TO_APPROVE`. Any "fix then publish" flow needs the scraped re-gate first.
It is the first task of this work, not a side quest.

## Goals

1. Clicking a fixable blocker badge opens a popup showing **only the fields that
   blocker names** — nothing else from the product.
2. One submit: **save → re-gate → publish if fully clear**.
3. Validate on submit; surface Laravel's per-field errors **on the right input**.
4. Give `ScrapedCatalogueService` a public `regate()`, mirroring the 3D service.

## Non-goals

- **No 3D blockers.** `awaiting_model_file`, `license_review`, `multi_file_review`,
  `estimates_unverified` keep `Model3dRowTools`. This endpoint refuses non-scraped
  products outright.
- **No source-truth blockers.** `stock_unreadable`, `source_dead`, `needs_re-review`
  are not staff-typed facts. They stay inert badges with a tooltip explaining why.
  (`stock_estimate` is also absent from `PRODUCT_RULES` today — deliberately left
  that way.)
- **No new business rules.** No "UV implies printable", no dims-fit-print-zone
  check. Those don't exist anywhere in the codebase yet and shouldn't be invented
  in a popup.
- **No change to `apiError`'s** flattening — too many call sites depend on it.

## Scope: which blockers are fixable here

Derived from `CompletenessGate::reasons()` (`CompletenessGate.php:20-44`), which is
the entire scraped gate:

| Blocker token | Gate condition | Fixable here | Fields shown |
|---|---|---|---|
| `missing_dimensions` | `!dims.l \|\| !dims.w \|\| !dims.h \|\| weight === null` | ✅ | Length, Width, Height (mm) · Weight (g) |
| `not_printable` | `!is_printable \|\| print_method === null` | ✅ | Printable? · Print method (UV/FDM/RESIN) |
| `missing_price` | `base_cost === null \|\| base_cost <= 0` | ✅ | Base cost (SGD) |
| `stock_unreadable` | `stock_estimate === null` | ❌ | — inert badge + tooltip |
| `source_dead` | set at `ScrapedCatalogueService.php:77` | ❌ | — inert badge + tooltip |
| `needs_re-review` | price drift > `drift_pct` (`:98`) | ❌ | — inert badge + tooltip |

Note `missing_dimensions` covers **both** dims and weight, and `not_printable`
covers **both** `is_printable` and `print_method`. One badge, one field group.
A row blocked on two fixable tokens shows both groups in one popup.

## Backend

### 1. `ScrapedCatalogueService::regate(Product $product): Product`

New public method. Body is the existing `evaluateAndSetState()` logic, with one
deliberate difference mirroring `Model3dCatalogueService::regate()` (`:436`):

**re-gate never lands on `PUBLISHED`**, even when `catalogue.auto_publish` is on.
It resolves to `CANNOT_PUBLISH` or `READY_TO_APPROVE`, and a human presses publish.
Auto-publish is a policy about *scraper ingest*, not about staff edits.

`evaluateAndSetState()` stays private and keeps its auto-publish branch, so
`ingest()` / `resync()` behaviour is unchanged. Only the reason-computation and
state-write are shared.

### 2. `POST /api/admin/catalogue/{product}/resolve-blockers`

New method on `AdminCatalogueController`, beside `publish`. Route registered in
the staff block, `routes/api.php` (near `:124-141`). Staff-gated in-controller
with `abort_unless($request->user()->isStaff(), 403)` — same as every sibling.

**Guards**, in order:

1. `abort_unless($product->class === ProductClass::ScrapedUv, 422)` — 3D and CORE
   have their own paths.
2. Refuse unless `publish_state` is `CANNOT_PUBLISH` or `PENDING` → 422. Running
   this against an already-`PUBLISHED` row would be a confusingly-named no-op.

**Validation.** All fields optional; validate only what was sent (the `sometimes`
pattern from `AdminProductController::update`, `:691-697`). Existing rules from
`PRODUCT_RULES` (`AdminProductController.php:59-75`) plus sanity ceilings so a
fat-fingered entry can't pass the gate:

```php
'base_cost'    => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
'weight'       => ['sometimes', 'numeric', 'gt:0', 'max:100000'],   // grams
'dimensions'   => ['sometimes', 'array'],
'dimensions.l' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
'dimensions.w' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
'dimensions.h' => ['required_with:dimensions', 'numeric', 'gt:0', 'max:2000'],
'print_method' => ['sometimes', 'string', 'in:UV,FDM,RESIN'],
'is_printable' => ['sometimes', 'boolean'],
```

Ceilings: dimensions 2000 mm, weight 100 kg, base cost SGD 1,000,000. These are
absurdity bounds, not business limits — they exist to catch a slipped decimal.

The endpoint accepts **only** these six fields. Anything else is ignored, which is
the point of not reusing `PATCH /admin/products/{id}`: that route's surface is the
whole product including superadmin-only `price_override` / `min_order_qty`
(stripped for staff at `:722-725`). A narrow endpoint needs no stripping.

**Flow**, in one transaction:

```
validate → fill (dimensions gets + ['unit' => 'mm']) → save → audit log
        → scraped->regate($product)
        → if publish_state === ReadyToApprove: scraped->publish($product)
```

**Response** — always 200 when the save succeeded:

```json
{
  "product": { ... },
  "published": true,
  "cannot_publish_reasons": []
}
```

`published: false` with a non-empty `cannot_publish_reasons` means *the save
worked, the row is still blocked*. A **422 means the input was bad, never that the
product merely stayed blocked** — that distinction is what stops typed work from
being thrown away.

### 3. `index` row payload gains current values

`AdminCatalogueController::index`'s row transform (`:130-147`) returns
`cannot_publish_reasons` but none of the fields those reasons name. Add
`base_cost`, `weight`, `dimensions`, `print_method`, `is_printable` so the popup
prefills from the row already in the store — no second fetch, no loading state.
The values are already on the loaded model; this is a serializer change only.

## Frontend

### 4. Blocker badges become buttons

In the blockers cell (`CatalogueAdminPage.tsx:694-704`): on `SCRAPED_UV` rows,
tokens in the fixable set render as a `<button>` wrapping the `Badge`, opening the
popup. Everything else stays exactly as today, wrapped in a `Tooltip` naming why
it can't be fixed here (e.g. *"Stock comes from the source listing — resolves on
the next sync."*).

What is clickable looks clickable; what isn't, doesn't.

The dead-end copy at `:735-741` is kept for rows whose blockers are all
source-truth, and suppressed when a fixable one is present.

### 5. `<ResolveBlockersModal>`

New component, `frontend/src/components/admin/ResolveBlockersModal.tsx`. Built on
the existing `ui/Modal` (`size="md"`) — portal, focus trap, Esc, footer slot, all
already there. Plain controlled `useState`, matching every other form in this repo
(no form library: package.json has no react-hook-form / formik / zod).

Props: `product` (the row), `open`, `onClose`, `onResolved(result)`.

**It renders only the field groups the row's blockers name** — the core of the
request. Field groups per the scope table above. Inputs are the house `ui/Input`
and `ui/Select`, whose `error?: string` prop already drives `aria-invalid`,
`border-danger`, and a `role="alert"` message (`Input.tsx:8-9, 51-64`).

Client-side checks mirror the server rules exactly (same bounds, same enum) for
instant feedback. The server remains the source of truth; the client check is a
courtesy, never the gate.

Footer: **Cancel** · **Save and publish** (submitting → disabled + busy).

**Outcomes:**

| Result | UI |
|---|---|
| `published: true` | Close, `useToast()` success — "Published." Row refreshes. |
| `published: false`, reasons remain | **Stay open.** Swap the form for the remaining blockers and why each can't be fixed here. Toast: "Saved. Still blocked by …". |
| 422 | Stay open, map errors onto inputs, nothing lost. |

### 6. `apiFieldErrors(err): Record<string, string>`

New helper beside `apiError` in `frontend/src/lib/api.ts`. Returns Laravel's
`errors` bag keyed as sent (`dimensions.l` → that input's `error` prop), first
message per field. `apiError` is **untouched** — it flattens the bag into one
string and many call sites depend on that.

### 7. Store

`catalogueAdminStore` gains `resolveBlockers(id, payload)`, returning the response
object or `null` on failure so the modal can stay open — the existing convention
(`catalogueAdminStore.ts:149-150`). `await ensureCsrf()` before the POST, as every
mutation in this store already does (`:111, 152, 168, 187`).

## Testing

**Pest** — `tests/Feature/AdminCatalogueTest.php` (extends the existing file; its
`attaches a model file and clears the missing_model_file hold` test at `:139` is
the closest template):

1. Fixes every blocker → 200, `published: true`, `publish_state = PUBLISHED`.
2. Fixes dims+weight but `stock_estimate` is null → 200, `published: false`,
   `cannot_publish_reasons = ['stock_unreadable']`, **and the weight is persisted**.
3. `weight: 0` → 422, `errors.weight` present, product untouched.
4. `weight: 500000` (over the ceiling) → 422.
5. `print_method: 'LASER'` → 422.
6. A `MODEL_3D` product → 422.
7. An already-`PUBLISHED` product → 422.
8. Non-staff → 403.
9. `regate()` with auto-publish **on** → lands `READY_TO_APPROVE`, not `PUBLISHED`.

`AdminCatalogueTest.php:110` — *"refuses to publish a CANNOT_PUBLISH item"* —
**must stay green.** This endpoint re-gates *before* publishing; it never bypasses
the gate. If that test ever needs changing, the design is wrong.

**Vitest** — `ResolveBlockersModal.test.tsx` (new; note there is no
`CatalogueAdminPage.test.tsx` today, and this design doesn't add one):

1. Row with `missing_dimensions` only → renders dims + weight, **not** price.
2. Row with `missing_dimensions` + `missing_price` → renders both groups.
3. Client-side invalid → submit blocked, error on the field, no request fired.
4. 422 response → error lands on the named input, modal stays open.
5. `published: false` → modal stays open showing the remaining blocker.

## Risks

- **`regate()` extraction touches the ingest path.** Mitigated by keeping
  `evaluateAndSetState()` private and unchanged in behaviour; `ScrapedCatalogueTest`
  covers it and must stay green.
- **Staff typing facts the scraper couldn't read** means the gate now trusts human
  entry over source data. This is consistent with rule 3 in `README.md` — scraped
  data is never authoritative; procurement-time re-check is the truth. A wrong
  weight here surfaces at procurement, as it would from any other source.
- **Every edit is audit-logged** (`AdminProductController.php:741` pattern), so a
  bad manual entry is attributable.
