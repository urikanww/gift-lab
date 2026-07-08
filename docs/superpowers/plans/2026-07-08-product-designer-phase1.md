# Product Designer — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the customization-page quick wins — layout redesign, name/text removal, adjustable quantity with a per-product superadmin minimum-order-quantity (MOQ), and the "upload finished look" production fallback wired into the existing proof workflow.

**Architecture:** Backend adds one nullable product column (`min_order_qty`) and validates it on quote creation; the customization JSON gains three fallback fields. Frontend removes the fabric text tool, replaces the quantity `Select` with a clamped stepper, restructures `ProductDesignerPage` into a two-column studio (hero preview + sticky rail), and adds a `Design here | Upload finished look` mode with a reference-image uploader. The fallback reuses the existing `/uploads/artwork` endpoint and the `Quote → Proof (SENT/CHANGES_REQUESTED/APPROVED)` loop — no new review workflow.

**Tech Stack:** Laravel 11 (PHPUnit feature tests), React + TypeScript + Vite (Vitest), Zustand, Fabric.js, Tailwind.

**Design spec:** `docs/superpowers/specs/2026-07-08-product-designer-enhancement-design.md`

---

## File Structure

**Backend (create):**
- `database/migrations/2026_07_08_000002_add_min_order_qty_to_products.php` — new nullable-with-default column.

**Backend (modify):**
- `app/Models/Product.php` — add `min_order_qty` to `$fillable` + integer cast.
- `app/Http/Resources/ProductResource.php` — expose `min_order_qty` on the public product.
- `app/Http/Controllers/AdminProductController.php` — serialize `min_order_qty`; validate + superadmin-gate it on `update()` (mirrors `price_override`).
- `app/Http/Requests/StoreQuoteRequest.php` — enforce MOQ per line; validate new customization fields (`mode`, `reference_refs`, `placement_notes`) + existence-check reference refs.

**Frontend (create):**
- `frontend/src/components/FinishedLookUploader.tsx` — the fallback panel (reference images + logo + notes).
- `frontend/src/components/FinishedLookUploader.test.tsx`
- `frontend/src/components/QuantityStepper.tsx` — clamped numeric stepper.
- `frontend/src/components/QuantityStepper.test.tsx`

**Frontend (modify):**
- `frontend/src/types.ts` — `Product.min_order_qty`; `Customization` fallback fields; `AdminProduct.min_order_qty`.
- `frontend/src/lib/uploadArtwork.ts` — add `uploadArtworkFile(file)` for raw File uploads (reference images).
- `frontend/src/components/DesignerCanvas.tsx` — remove the text tool + all `hasText` plumbing.
- `frontend/src/pages/ProductDesignerPage.tsx` — remove `has_text`; two-column layout; mode toggle; quantity stepper; wire fallback add-to-cart.
- `frontend/src/stores/cartStore.ts` — `hasCustomization` recognises the fallback; drop `has_text` from the estimate call (already absent).
- `frontend/src/pages/ProductAdminDetailPage.tsx` — superadmin MOQ input on the product edit form.
- `frontend/src/pages/QuoteDetailPage.tsx` — surface `reference_refs` + `placement_notes` to staff.

**Note on scope:** Auto-defaulting freeform products into the fallback mode requires surface classification that lands in Phase 2/3. In Phase 1 the mode toggle is always available and defaults to **Design here**; the auto-default is deliberately deferred.

---

## Group A — Backend: per-product MOQ

### Task A1: Migration — add `min_order_qty`

**Files:**
- Create: `database/migrations/2026_07_08_000002_add_min_order_qty_to_products.php`

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimum order quantity per product. Superadmin-set (mirrors price_override):
 * a buyer cannot order fewer than this many units. Default 1 preserves existing
 * behaviour for every current product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->unsignedInteger('min_order_qty')->default(1)->after('price_override');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('min_order_qty');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: migrates `2026_07_08_000002_add_min_order_qty_to_products` with no error.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_08_000002_add_min_order_qty_to_products.php
git commit -m "feat: add min_order_qty column to products"
```

### Task A2: Product model — fillable + cast

**Files:**
- Modify: `app/Models/Product.php:43` (`$fillable`), `app/Models/Product.php:73` (`casts()`)

- [ ] **Step 1: Add to `$fillable`** — insert `'min_order_qty',` immediately after `'price_override',`:

```php
        'price_override',
        'min_order_qty',
```

- [ ] **Step 2: Add the cast** — inside `casts()`, after the `'price_override' => 'decimal:2',` line:

```php
            'price_override' => 'decimal:2',
            'min_order_qty' => 'integer',
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/Product.php
git commit -m "feat: make min_order_qty fillable + cast to int"
```

### Task A3: Public resource — expose `min_order_qty`

**Files:**
- Test: `tests/Feature/CatalogueTest.php`
- Modify: `app/Http/Resources/ProductResource.php:57`

- [ ] **Step 1: Write the failing test** — add to `tests/Feature/CatalogueTest.php`:

```php
public function test_product_resource_exposes_min_order_qty(): void
{
    $product = Product::factory()->create(['publish_state' => \App\Enums\PublishState::Published, 'min_order_qty' => 25]);
    \App\Models\Variant::factory()->create(['product_id' => $product->id]);

    $this->getJson("/api/catalogue/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.min_order_qty', 25);
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `php artisan test --filter test_product_resource_exposes_min_order_qty`
Expected: FAIL (`min_order_qty` missing from payload).

- [ ] **Step 3: Add the field** — in `ProductResource::toArray`, after the `'print_zone' => $this->print_zone,` line:

```php
            'print_zone' => $this->print_zone,
            // Minimum order quantity (superadmin-set); default 1.
            'min_order_qty' => (int) $this->min_order_qty,
```

- [ ] **Step 4: Run it, verify it passes**

Run: `php artisan test --filter test_product_resource_exposes_min_order_qty`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/ProductResource.php tests/Feature/CatalogueTest.php
git commit -m "feat: expose min_order_qty on public product resource"
```

### Task A4: Admin — serialize + superadmin-gated update of `min_order_qty`

**Files:**
- Test: `tests/Feature/AdminProductManagementTest.php`
- Modify: `app/Http/Controllers/AdminProductController.php` — `update()` rules (~line 391) + `serialize()` (~line 554)

- [ ] **Step 1: Write the failing tests** — add to `tests/Feature/AdminProductManagementTest.php`:

```php
public function test_superadmin_can_set_min_order_qty(): void
{
    $admin = \App\Models\User::factory()->superadmin()->create();
    $product = Product::factory()->create(['min_order_qty' => 1]);

    $this->actingAs($admin)
        ->patchJson("/api/admin/products/{$product->id}", ['min_order_qty' => 50])
        ->assertOk()
        ->assertJsonPath('data.min_order_qty', 50);

    $this->assertSame(50, $product->fresh()->min_order_qty);
}

public function test_staff_admin_cannot_set_min_order_qty(): void
{
    $staff = \App\Models\User::factory()->staffAdmin()->create();
    $product = Product::factory()->create(['min_order_qty' => 1]);

    $this->actingAs($staff)
        ->patchJson("/api/admin/products/{$product->id}", ['min_order_qty' => 50])
        ->assertOk();

    // Silently dropped for non-superadmins (mirrors price_override).
    $this->assertSame(1, $product->fresh()->min_order_qty);
}
```

Confirm the factory state helpers (`superadmin()`, `staffAdmin()`) exist in `UserFactory`; if the project uses different names (e.g. `->state(['role' => 'superadmin'])`), match the existing pattern used elsewhere in this test file.

- [ ] **Step 2: Run, verify failure**

Run: `php artisan test --filter AdminProductManagementTest`
Expected: the two new tests FAIL.

- [ ] **Step 3: Add the update rule** — in `update()`, after the `price_override` rule line:

```php
        $rules['price_override'] = ['sometimes', 'nullable', 'numeric', 'min:0'];
        // Superadmin-set minimum order quantity (>=1). Gated below like price_override.
        $rules['min_order_qty'] = ['sometimes', 'integer', 'min:1', 'max:100000'];
```

- [ ] **Step 4: Gate it to superadmins** — extend the existing guard block:

```php
        if (! $request->user()->isSuperadmin()) {
            unset($validated['price_override']);
            unset($validated['min_order_qty']);
        }
```

- [ ] **Step 5: Serialize it** — in `serialize()`, after the `'price_override' => $product->price_override,` line:

```php
            'price_override' => $product->price_override,
            'min_order_qty' => (int) $product->min_order_qty,
```

- [ ] **Step 6: Run, verify passes**

Run: `php artisan test --filter AdminProductManagementTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/AdminProductController.php tests/Feature/AdminProductManagementTest.php
git commit -m "feat: superadmin-gated min_order_qty on product update + serialize"
```

### Task A5: Quote validation — enforce MOQ per line

**Files:**
- Test: `tests/Feature/QuoteFlowTest.php`
- Modify: `app/Http/Requests/StoreQuoteRequest.php` — `withValidator()` loop (~line 121)

- [ ] **Step 1: Write the failing test** — add to `tests/Feature/QuoteFlowTest.php`:

```php
public function test_quote_rejects_qty_below_product_min_order_qty(): void
{
    $user = \App\Models\User::factory()->create();
    $product = Product::factory()->create([
        'publish_state' => \App\Enums\PublishState::Published,
        'min_order_qty' => 25,
    ]);
    \App\Models\Variant::factory()->create(['product_id' => $product->id]);

    $this->actingAs($user)->postJson('/api/quotes', [
        'company_id' => $user->company_id,
        'line_items' => [
            ['product_id' => $product->id, 'qty' => 10],
        ],
    ])->assertStatus(422)
      ->assertJsonValidationErrors('line_items.0.qty');
}
```

- [ ] **Step 2: Run, verify failure**

Run: `php artisan test --filter test_quote_rejects_qty_below_product_min_order_qty`
Expected: FAIL (quote accepts qty 10).

- [ ] **Step 3: Enforce in the loop** — inside the `foreach ($lineItems as $index => $line)` block in `withValidator`, after the `$product = $products->get($productId);` publish check, add:

```php
                // MOQ floor: a line below the product's minimum order quantity
                // is rejected here (client stepper is a convenience, not the guard).
                $qty = isset($line['qty']) ? (int) $line['qty'] : 0;
                if ($product !== null && $qty > 0 && $qty < (int) $product->min_order_qty) {
                    $validator->errors()->add(
                        "line_items.{$index}.qty",
                        "Minimum order for this product is {$product->min_order_qty}."
                    );
                }
```

- [ ] **Step 4: Run, verify passes**

Run: `php artisan test --filter test_quote_rejects_qty_below_product_min_order_qty`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/StoreQuoteRequest.php tests/Feature/QuoteFlowTest.php
git commit -m "feat: enforce per-product MOQ on quote creation"
```

---

## Group B — Backend: fallback customization fields

### Task B1: StoreQuoteRequest — validate fallback fields + existence-check reference refs

**Files:**
- Test: `tests/Feature/QuoteRequestValidationTest.php`
- Modify: `app/Http/Requests/StoreQuoteRequest.php` — `rules()` (~line 74) + `withValidator()` ref loop (~line 159)

- [ ] **Step 1: Write the failing test** — add to `tests/Feature/QuoteRequestValidationTest.php`:

```php
public function test_quote_accepts_buyer_uploaded_fallback_fields(): void
{
    $user = \App\Models\User::factory()->create();
    $product = Product::factory()->create(['publish_state' => \App\Enums\PublishState::Published]);
    \App\Models\Variant::factory()->create(['product_id' => $product->id]);

    $disk = \Illuminate\Support\Facades\Storage::disk((string) config('filesystems.artwork_disk'));
    $disk->put('artwork/ref-photo.png', 'x');

    $this->actingAs($user)->postJson('/api/quotes', [
        'company_id' => $user->company_id,
        'line_items' => [[
            'product_id' => $product->id,
            'qty' => $product->min_order_qty,
            'customization' => [
                'mode' => 'buyer_uploaded',
                'reference_refs' => ['artwork/ref-photo.png'],
                'placement_notes' => 'Centre of the lid, ~4cm wide.',
            ],
        ]],
    ])->assertCreated();
}

public function test_quote_rejects_reference_ref_that_does_not_resolve(): void
{
    $user = \App\Models\User::factory()->create();
    $product = Product::factory()->create(['publish_state' => \App\Enums\PublishState::Published]);
    \App\Models\Variant::factory()->create(['product_id' => $product->id]);

    $this->actingAs($user)->postJson('/api/quotes', [
        'company_id' => $user->company_id,
        'line_items' => [[
            'product_id' => $product->id,
            'qty' => $product->min_order_qty,
            'customization' => [
                'mode' => 'buyer_uploaded',
                'reference_refs' => ['artwork/does-not-exist.png'],
            ],
        ]],
    ])->assertStatus(422)
      ->assertJsonValidationErrors('line_items.0.customization.reference_refs.0');
}
```

- [ ] **Step 2: Run, verify failure**

Run: `php artisan test --filter QuoteRequestValidationTest`
Expected: both new tests FAIL.

- [ ] **Step 3: Add the rules** — in `rules()`, after the `customization.text` rule:

```php
            'line_items.*.customization.text' => ['nullable', 'string', 'max:500'],
            // Fallback ("upload finished look"): the buyer describes intent that
            // production proofs before printing, rather than a ready print file.
            'line_items.*.customization.mode' => ['nullable', 'string', 'in:designer,buyer_uploaded'],
            'line_items.*.customization.placement_notes' => ['nullable', 'string', 'max:2000'],
            'line_items.*.customization.reference_refs' => ['nullable', 'array', 'max:6'],
            'line_items.*.customization.reference_refs.*' => ['string', 'max:2048', 'regex:#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#'],
```

- [ ] **Step 4: Existence-check reference refs** — in `withValidator`, after the existing `foreach (['artwork_ref', 'print_file_ref'] as $refKey)` block, add:

```php
                // Reference images (fallback) get the same on-disk existence
                // guard as the print refs — a well-formed but foreign key must
                // not reach the floor.
                $refs = $line['customization']['reference_refs'] ?? null;
                if (is_array($refs)) {
                    foreach ($refs as $refIndex => $ref) {
                        if (is_string($ref)
                            && preg_match('#^artwork/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{1,10}$#', $ref) === 1
                            && ! $artworkDisk->exists($ref)
                        ) {
                            $validator->errors()->add(
                                "line_items.{$index}.customization.reference_refs.{$refIndex}",
                                'Reference image does not resolve to an uploaded file.'
                            );
                        }
                    }
                }
```

- [ ] **Step 5: Run, verify passes**

Run: `php artisan test --filter QuoteRequestValidationTest`
Expected: PASS

- [ ] **Step 6: Verify persistence** — confirm the quote service stores the whole `customization` array (so the new keys land on the `LineItem`). Read `app/Services/QuoteService.php`; if it maps `customization` through verbatim (JSON column), no change is needed. If it whitelists keys, add `mode`, `reference_refs`, `placement_notes` to that whitelist. Add a follow-up assertion to the first test:

```php
    // (append to test_quote_accepts_buyer_uploaded_fallback_fields, after assertCreated)
    $line = \App\Models\LineItem::query()->latest('id')->first();
    $this->assertSame('buyer_uploaded', $line->customization['mode']);
    $this->assertSame(['artwork/ref-photo.png'], $line->customization['reference_refs']);
```

Run: `php artisan test --filter test_quote_accepts_buyer_uploaded_fallback_fields`
Expected: PASS (adjust the whitelist if it fails).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/StoreQuoteRequest.php tests/Feature/QuoteRequestValidationTest.php app/Services/QuoteService.php
git commit -m "feat: validate + persist buyer-uploaded fallback customization fields"
```

---

## Group C — Frontend types + upload helper

### Task C1: Types — MOQ + fallback fields

**Files:**
- Modify: `frontend/src/types.ts` — `Product` (~line 99), `Customization` (~line 105), `AdminProduct` (~line 263)

- [ ] **Step 1: Add `min_order_qty` to `Product`** — after the `print_zone?` line:

```ts
  /** Admin-authored decoration zone (model-space mm); null when unset. */
  print_zone?: PrintZone | null;
  /** Minimum order quantity (superadmin-set); default 1. */
  min_order_qty?: number;
```

- [ ] **Step 2: Add fallback fields to `Customization`** — after the `layout?` field:

```ts
  layout?: object | null;
  /** Customization mode: in-app designer output, or buyer-uploaded intent. */
  mode?: 'designer' | 'buyer_uploaded';
  /** Fallback: reference images of the desired finished look (storage refs). */
  reference_refs?: string[];
  /** Fallback: free-text placement notes for production. */
  placement_notes?: string | null;
```

- [ ] **Step 3: Add `min_order_qty` to `AdminProduct`** — after its `print_zone?` line:

```ts
  /** Persisted admin print zone for MODEL_3D items; null when unset. */
  print_zone?: PrintZone | null;
  /** Minimum order quantity (superadmin-set); default 1. */
  min_order_qty?: number;
```

- [ ] **Step 4: Typecheck**

Run: `cd frontend && npm run build` (or `npx tsc --noEmit`)
Expected: no type errors introduced.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/types.ts
git commit -m "feat: types for min_order_qty + fallback customization fields"
```

### Task C2: Upload helper — raw File uploads for reference images

**Files:**
- Modify: `frontend/src/lib/uploadArtwork.ts`

- [ ] **Step 1: Add `uploadArtworkFile`** — append to `frontend/src/lib/uploadArtwork.ts`:

```ts
/**
 * Upload a raw File (reference image for the "upload finished look" fallback,
 * or a logo file) to the same private artwork store and return its ref. Reuses
 * POST /uploads/artwork, which accepts png/jpg/jpeg/webp up to 10 MB.
 */
export async function uploadArtworkFile(file: File): Promise<string> {
  await ensureCsrf();
  const form = new FormData();
  form.append('artwork', file, file.name || 'reference');
  const { data } = await api.post<{ ref: string; url: string }>('/uploads/artwork', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data.ref;
}
```

- [ ] **Step 2: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/lib/uploadArtwork.ts
git commit -m "feat: uploadArtworkFile helper for reference/logo file uploads"
```

---

## Group D — Frontend: remove the name/text tool

### Task D1: DesignerCanvas — strip text plumbing

**Files:**
- Modify: `frontend/src/components/DesignerCanvas.tsx`
- Test: `frontend/src/components/DesignerCanvas.test.tsx`

- [ ] **Step 1: Update the `onLogoChange` contract** — change the prop type (line ~31) to drop `hasText`:

```ts
  onLogoChange?: (info: { hasLogo: boolean; size: LogoSize }) => void;
```

- [ ] **Step 2: Remove text state + refs** — delete `hasText`, `setHasText`, `hasTextRef` (lines ~90-91) and every reference to them. In `undo`, `deleteSelected`, `applyBand`, `addLogoFromDataUrl`, update each `onLogoChange?.({...})` call to pass only `{ hasLogo, size }`.

- [ ] **Step 3: Delete `addText` and the `Textbox` import** — remove the `addText` function (lines ~368-395) and drop `Textbox` from the fabric import (line 2). Remove the "Name / text" `<fieldset>` (lines ~913-925) and the `TextTool` component (lines ~962-1018).

- [ ] **Step 4: Simplify capture** — in `capture()`, remove the `textContent` block (lines ~650-654) and drop `text` from the emitted `customization` (line ~663). The `layout.objects` mapping stays (it already handles any object type generically); no Textbox will be present.

- [ ] **Step 5: Update the test** — in `DesignerCanvas.test.tsx`, remove/replace any assertion referencing the text tool or `hasText`. If a test adds text, delete it. Ensure a test asserts the "Name / text" legend is **absent**:

```ts
it('does not render a name/text tool', () => {
  render(<DesignerCanvas onCapture={() => {}} />);
  expect(screen.queryByText(/name \/ text/i)).toBeNull();
});
```

- [ ] **Step 6: Run the test**

Run: `cd frontend && npx vitest run src/components/DesignerCanvas.test.tsx`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/DesignerCanvas.tsx frontend/src/components/DesignerCanvas.test.tsx
git commit -m "feat: remove name/text tool from designer canvas"
```

### Task D2: ProductDesignerPage + cartStore — drop `has_text`

**Files:**
- Modify: `frontend/src/pages/ProductDesignerPage.tsx`, `frontend/src/stores/cartStore.ts`

- [ ] **Step 1: Update logo state** — in `ProductDesignerPage`, change the `logo` state (line ~49) to drop `hasText`:

```ts
  const [logo, setLogo] = useState<{ hasLogo: boolean; size: string }>({
    hasLogo: false,
    size: 'M',
  });
```

- [ ] **Step 2: Remove `has_text` from the estimate** — delete the `hasText` const (line ~165) and remove `has_text: hasText` from the `/price-estimate` body (line ~178). Update `hasCustomization` (line ~163) to `logo.hasLogo || !!artwork`. Remove `hasText` from the effect dependency array (line ~198).

- [ ] **Step 3: cartStore** — `hasCustomization` (line 6) already keys off `logo_size || artwork_ref`; leave it (Task F updates it for the fallback). No `has_text` is sent from `cartStore` — confirm and leave unchanged.

- [ ] **Step 4: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors (the `onLogoChange={setLogo}` now matches the narrowed type).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/ProductDesignerPage.tsx
git commit -m "feat: drop has_text from designer price estimate"
```

---

## Group E — Frontend: adjustable quantity with MOQ

### Task E1: QuantityStepper component

**Files:**
- Create: `frontend/src/components/QuantityStepper.tsx`, `frontend/src/components/QuantityStepper.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import QuantityStepper from './QuantityStepper';

describe('QuantityStepper', () => {
  it('clamps below-min values up to min on blur', () => {
    const onChange = vi.fn();
    render(<QuantityStepper value={25} min={25} onChange={onChange} />);
    const input = screen.getByLabelText(/quantity/i) as HTMLInputElement;
    fireEvent.change(input, { target: { value: '5' } });
    fireEvent.blur(input);
    expect(onChange).toHaveBeenLastCalledWith(25);
  });

  it('decrement never goes below min', () => {
    const onChange = vi.fn();
    render(<QuantityStepper value={25} min={25} onChange={onChange} />);
    fireEvent.click(screen.getByLabelText(/decrease/i));
    expect(onChange).toHaveBeenLastCalledWith(25);
  });
});
```

- [ ] **Step 2: Run, verify failure**

Run: `cd frontend && npx vitest run src/components/QuantityStepper.test.tsx`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement**

```tsx
import { useState, useEffect } from 'react';
import { Button, cn } from '../ui';

interface Props {
  value: number;
  min?: number;
  max?: number;
  step?: number;
  onChange: (qty: number) => void;
  className?: string;
}

/**
 * Adjustable quantity control clamped to a minimum order quantity. Typing is
 * free while focused; the value is clamped to [min, max] on blur/step so an
 * in-progress edit isn't fought, but the committed value is always valid.
 */
export default function QuantityStepper({ value, min = 1, max = 100000, step = 1, onChange, className }: Props) {
  const [draft, setDraft] = useState(String(value));
  useEffect(() => setDraft(String(value)), [value]);

  const clamp = (n: number) => Math.min(max, Math.max(min, Math.floor(n)));
  const commit = (raw: string) => {
    const n = Number(raw);
    const next = Number.isFinite(n) ? clamp(n) : min;
    setDraft(String(next));
    onChange(next);
  };

  return (
    <div className={cn('flex items-center gap-1', className)}>
      <Button variant="outline" size="sm" aria-label="Decrease quantity" onClick={() => onChange(clamp(value - step))}>
        −
      </Button>
      <input
        type="number"
        inputMode="numeric"
        aria-label="Quantity"
        min={min}
        max={max}
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={(e) => commit(e.target.value)}
        className="w-16 rounded-md border border-border bg-surface px-2 py-1.5 text-center text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
      <Button variant="outline" size="sm" aria-label="Increase quantity" onClick={() => onChange(clamp(value + step))}>
        +
      </Button>
    </div>
  );
}
```

- [ ] **Step 4: Run, verify passes**

Run: `cd frontend && npx vitest run src/components/QuantityStepper.test.tsx`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/QuantityStepper.tsx frontend/src/components/QuantityStepper.test.tsx
git commit -m "feat: clamped QuantityStepper component"
```

### Task E2: Wire the stepper into the designer

**Files:**
- Modify: `frontend/src/pages/ProductDesignerPage.tsx` — `qty` state (~line 72), the QTY `Select` in the action bar (~line 414)

- [ ] **Step 1: Default qty to MOQ** — replace `const [qty, setQty] = useState(50);` with a MOQ-aware initial value set once the product loads. In the `load` success path (after `setProduct(data.data)`), add:

```ts
      setProduct(data.data);
      setQty(Math.max(1, data.data.min_order_qty ?? 1));
```

Keep `const [qty, setQty] = useState(1);` as the initial state.

- [ ] **Step 2: Replace the Select with the stepper** — swap the `<Select label="Quantity" ...>` block (and its `QTY_OPTIONS` map) for:

```tsx
                <div>
                  <span className="mb-1 block text-2xs font-medium text-fg-subtle">Quantity</span>
                  <QuantityStepper
                    value={qty}
                    min={Math.max(1, product.min_order_qty ?? 1)}
                    onChange={setQty}
                  />
                  {(product.min_order_qty ?? 1) > 1 && (
                    <p className="mt-1 text-2xs text-fg-subtle">Min order {product.min_order_qty}</p>
                  )}
                </div>
```

Import `QuantityStepper` at the top; remove the now-unused `QTY_OPTIONS` constant.

- [ ] **Step 3: Typecheck + manual smoke**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors. (Runtime verification happens in the Group-G preview pass.)

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/ProductDesignerPage.tsx
git commit -m "feat: adjustable quantity stepper clamped to product MOQ"
```

---

## Group F — Frontend: upload-finished-look fallback

### Task F1: FinishedLookUploader component

**Files:**
- Create: `frontend/src/components/FinishedLookUploader.tsx`, `frontend/src/components/FinishedLookUploader.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import FinishedLookUploader from './FinishedLookUploader';

vi.mock('../lib/uploadArtwork', () => ({
  uploadArtworkFile: vi.fn(async () => 'artwork/ref-1.png'),
}));

describe('FinishedLookUploader', () => {
  it('emits refs + notes when a reference image is added and notes typed', async () => {
    const onChange = vi.fn();
    render(<FinishedLookUploader onChange={onChange} />);

    const file = new File(['x'], 'look.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText(/reference image/i), { target: { files: [file] } });

    await waitFor(() => expect(onChange).toHaveBeenCalled());
    fireEvent.change(screen.getByLabelText(/placement notes/i), { target: { value: 'Centre lid' } });

    const last = onChange.mock.calls.at(-1)![0];
    expect(last.reference_refs).toContain('artwork/ref-1.png');
    expect(last.placement_notes).toBe('Centre lid');
  });
});
```

- [ ] **Step 2: Run, verify failure**

Run: `cd frontend && npx vitest run src/components/FinishedLookUploader.test.tsx`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement**

```tsx
import { useCallback, useState } from 'react';
import { uploadArtworkFile } from '../lib/uploadArtwork';
import { Button, cn, useOptionalToast } from '../ui';

export interface FinishedLookValue {
  reference_refs: string[];
  logo_ref: string | null;
  placement_notes: string;
}

interface Props {
  onChange: (value: FinishedLookValue) => void;
}

const MAX_REFERENCES = 6;

/**
 * Fallback panel: the buyer uploads reference image(s) of the finished look,
 * their logo file, and placement notes. Production proofs this before printing
 * (existing Quote → Proof loop); it never produces a ready-to-print file.
 */
export default function FinishedLookUploader({ onChange }: Props) {
  const { toast } = useOptionalToast();
  const [refs, setRefs] = useState<string[]>([]);
  const [logoRef, setLogoRef] = useState<string | null>(null);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);

  const emit = useCallback(
    (next: Partial<FinishedLookValue>) => {
      const value: FinishedLookValue = {
        reference_refs: next.reference_refs ?? refs,
        logo_ref: next.logo_ref !== undefined ? next.logo_ref : logoRef,
        placement_notes: next.placement_notes ?? notes,
      };
      onChange(value);
    },
    [refs, logoRef, notes, onChange],
  );

  const addReference = async (file: File) => {
    if (refs.length >= MAX_REFERENCES) return;
    setBusy(true);
    try {
      const ref = await uploadArtworkFile(file);
      const nextRefs = [...refs, ref];
      setRefs(nextRefs);
      emit({ reference_refs: nextRefs });
    } catch {
      toast({ title: 'Upload failed', description: 'Please try a PNG/JPG under 10 MB.', tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  const addLogo = async (file: File) => {
    setBusy(true);
    try {
      const ref = await uploadArtworkFile(file);
      setLogoRef(ref);
      emit({ logo_ref: ref });
    } catch {
      toast({ title: 'Upload failed', description: 'Please try a PNG/JPG under 10 MB.', tone: 'danger' });
    } finally {
      setBusy(false);
    }
  };

  const dropzone = 'flex flex-col items-center justify-center gap-1 rounded-md border border-dashed border-border-strong bg-surface-2/50 px-3 py-4 text-center text-sm cursor-pointer hover:border-primary hover:bg-surface-2';

  return (
    <div className="flex flex-col gap-3">
      <label className={cn(dropzone)}>
        <span className="font-medium text-fg">Reference image(s) of the final look</span>
        <span className="text-2xs text-fg-subtle">PNG or JPG, up to 6 images</span>
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          aria-label="Reference image"
          className="sr-only"
          onChange={(e) => {
            const f = e.target.files?.[0];
            if (f) void addReference(f);
            e.target.value = '';
          }}
        />
      </label>
      {refs.length > 0 && <p className="text-2xs text-fg-subtle">{refs.length} reference image(s) attached</p>}

      <label className={cn(dropzone)}>
        <span className="font-medium text-fg">Your logo file</span>
        <span className="text-2xs text-fg-subtle">PNG or JPG</span>
        <input
          type="file"
          accept="image/png,image/jpeg,image/webp"
          aria-label="Logo file"
          className="sr-only"
          onChange={(e) => {
            const f = e.target.files?.[0];
            if (f) void addLogo(f);
            e.target.value = '';
          }}
        />
      </label>
      {logoRef && <p className="text-2xs text-fg-subtle">Logo attached</p>}

      <label className="flex flex-col gap-1">
        <span className="text-2xs font-medium text-fg-subtle">Placement notes</span>
        <textarea
          aria-label="Placement notes"
          rows={3}
          maxLength={2000}
          value={notes}
          placeholder="e.g. centre of the lid, ~4cm wide"
          onChange={(e) => {
            setNotes(e.target.value);
            emit({ placement_notes: e.target.value });
          }}
          className="rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        />
      </label>

      <p className="text-2xs text-fg-subtle">
        We confirm producibility before printing. If something's off, we'll request changes.
      </p>
      {busy && <p className="text-2xs text-fg-subtle" role="status">Uploading…</p>}
    </div>
  );
}
```

- [ ] **Step 4: Run, verify passes**

Run: `cd frontend && npx vitest run src/components/FinishedLookUploader.test.tsx`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/FinishedLookUploader.tsx frontend/src/components/FinishedLookUploader.test.tsx
git commit -m "feat: FinishedLookUploader fallback panel"
```

### Task F2: Mode toggle + fallback add-to-cart

**Files:**
- Modify: `frontend/src/pages/ProductDesignerPage.tsx`

- [ ] **Step 1: Add mode state** — near the other state:

```ts
  const [mode, setMode] = useState<'designer' | 'buyer_uploaded'>('designer');
  const [finishedLook, setFinishedLook] = useState<import('../components/FinishedLookUploader').FinishedLookValue | null>(null);
```

- [ ] **Step 2: Render the toggle at the top of the control rail** (see Group G for placement):

```tsx
              <div className="flex overflow-hidden rounded-md border border-border text-sm" role="tablist" aria-label="Customization mode">
                <button
                  type="button"
                  role="tab"
                  aria-selected={mode === 'designer'}
                  onClick={() => setMode('designer')}
                  className={cn('flex-1 px-3 py-2', mode === 'designer' ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:text-fg')}
                >
                  Design here
                </button>
                <button
                  type="button"
                  role="tab"
                  aria-selected={mode === 'buyer_uploaded'}
                  onClick={() => setMode('buyer_uploaded')}
                  className={cn('flex-1 px-3 py-2', mode === 'buyer_uploaded' ? 'bg-primary text-primary-fg' : 'text-fg-muted hover:text-fg')}
                >
                  Upload finished look
                </button>
              </div>
```

- [ ] **Step 3: Conditionally render** — show the `DesignerCanvas` + designer rail controls only when `mode === 'designer'`; render `<FinishedLookUploader onChange={setFinishedLook} />` when `mode === 'buyer_uploaded'`.

- [ ] **Step 4: Branch `addToCart`** — at the top of `addToCart`, handle the fallback:

```ts
    if (mode === 'buyer_uploaded') {
      if (!finishedLook || (finishedLook.reference_refs.length === 0 && !finishedLook.logo_ref)) {
        toast({ title: 'Add a reference', description: 'Upload at least one image of the finished look.', tone: 'warning' });
        return;
      }
      addLine(product, selectedVariant, {
        ...(is3d ? { filament_color: filamentColor } : {}),
        mode: 'buyer_uploaded',
        reference_refs: finishedLook.reference_refs,
        artwork_ref: finishedLook.logo_ref ?? undefined,
        placement_notes: finishedLook.placement_notes || null,
      }, qty);
      toast({ title: 'Added to cart', description: product.name, tone: 'success' });
      navigate('/cart');
      return;
    }
```

Leave the existing designer path unchanged below this block.

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/ProductDesignerPage.tsx
git commit -m "feat: design-here / upload-finished-look mode toggle + fallback add-to-cart"
```

---

## Group G — Frontend: layout redesign (point 1)

### Task G1: Two-column studio

**Files:**
- Modify: `frontend/src/pages/ProductDesignerPage.tsx` — the returned JSX (~line 258 onward)

- [ ] **Step 1: Widen the container + restructure** — change the outer `Motion` `className` from `max-w-5xl` to `max-w-6xl`, and replace the vertically-stacked body with a two-column grid: a slim delivery/need-by top bar, then `grid gap-6 lg:grid-cols-[1.6fr_1fr]`. Left column = the hero preview (the existing `Model3dDecalPreview` / `DesignerCanvas` stage). Right column = a sticky rail (`lg:sticky lg:top-20`) containing, in order: the **mode toggle** (Task F2), filament colour (3D only, from `Model3dPersonalizer`), the `DesignerCanvas` control panel content, the quantity stepper + live price, and the primary CTA. Move the delivery `Card` content into the slim top bar.

Structural skeleton (fill with the existing pieces — do not invent new controls):

```tsx
  return (
    <AsyncBoundary /* …unchanged props… */>
      {product && (
        <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mx-auto flex w-full max-w-6xl flex-col gap-5">
          {/* Slim top bar: title + badges (left), delivery/need-by (right) */}
          <header className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-surface px-4 py-3">
            <div className="flex items-center gap-2">
              <h1 className="font-display text-xl leading-tight sm:text-2xl">{product.name}</h1>
              <Badge tone="brand" size="sm">Design studio</Badge>
              {product.print_method && <Badge tone="neutral" size="sm">{product.print_method}</Badge>}
            </div>
            {lead && (
              <div className="text-sm text-fg-muted">
                Arrives {fmtDate(lead.earliest)}–{fmtDate(lead.latest)}
                {/* keep the existing need-by Input in a compact popover/inline control */}
              </div>
            )}
          </header>

          <div className="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
            {/* LEFT — hero preview */}
            <div className="min-w-0">
              {is3d && zone && (
                <Model3dDecalPreview ref={decalRef} productKey={id!} filamentColor={filamentColor} zone={zone} artworkDataUrl={artwork?.dataUrl ?? null} />
              )}
              {/* DesignerCanvas stage (2D pad / photo) — control panel is moved to the rail */}
              {mode === 'designer' && (
                <DesignerCanvas
                  backgroundUrl={is3d ? (faceSnapshot?.dataUrl ?? null) : product.image_url}
                  onCapture={handleCapture}
                  onLogoChange={setLogo}
                  brandLogo={brandKit?.logo ?? null}
                  brandColors={brandKit?.colors ?? []}
                  canvasMm={zone ? { width: zone.width_mm, height: zone.height_mm } : faceSnapshot ? { width: faceSnapshot.canvasWidthMm, height: faceSnapshot.canvasHeightMm } : null}
                />
              )}
            </div>

            {/* RIGHT — sticky control rail */}
            <aside className="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
              {/* mode toggle (Task F2) */}
              {/* filament colour (3D only): {is3d && <Model3dPersonalizer onChange={setModel3dOptions} />} */}
              {mode === 'buyer_uploaded'
                ? <FinishedLookUploader onChange={setFinishedLook} />
                : null /* the DesignerCanvas control panel renders its own tools; keep them within the canvas for now */}
              {/* quantity stepper + live price + CTA */}
            </aside>
          </div>
        </Motion>
      )}
    </AsyncBoundary>
  );
```

Note: `DesignerCanvas` currently renders its own right-hand control panel (Task D leaves that intact). For Phase 1, keep the canvas + its panel together inside the LEFT column and use the RIGHT rail for mode toggle, filament, quantity, price and CTA. A full extraction of the canvas control panel into the rail is **not** required for Phase 1 — the goal is to remove dead vertical space and give the preview room, not to re-plumb Fabric. Prefer the smaller change.

- [ ] **Step 2: Preserve the mobile sticky action bar** — keep the existing bottom bar; ensure the rail stacks under the preview at `<lg` (grid collapses to one column automatically).

- [ ] **Step 3: Verify in the preview server** — start the dev server and drive the page:
  - `preview_start` the frontend dev server.
  - Navigate to a MODEL_3D product's design route.
  - `preview_screenshot` to confirm the whitespace is gone and the two-column layout renders.
  - `preview_console_logs` (level: error) — no runtime errors.
  - Toggle to "Upload finished look" and confirm the uploader renders.

- [ ] **Step 4: Run the page's existing tests**

Run: `cd frontend && npx vitest run src/pages/ProductDetailPage.test.tsx` and any `ProductDesigner`-related test.
Expected: PASS (update selectors if the redesign moved elements).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/ProductDesignerPage.tsx
git commit -m "feat: two-column studio layout, kill whitespace"
```

---

## Group H — Staff surface for the fallback

### Task H1: Show reference images + notes on the quote line

**Files:**
- Modify: `frontend/src/pages/QuoteDetailPage.tsx`

- [ ] **Step 1: Read the file** to find where a line item's `customization` is rendered to staff (artwork_ref preview / logo_size). Follow that exact pattern.

- [ ] **Step 2: Render the fallback fields** — where a line's customization is shown, add (guarded so designer lines are unaffected):

```tsx
{line.customization?.mode === 'buyer_uploaded' && (
  <div className="mt-2 rounded-md border border-warning/30 bg-warning-bg p-2 text-sm">
    <p className="font-medium text-fg">Buyer-uploaded finished look — proof before printing</p>
    {line.customization.placement_notes && (
      <p className="mt-1 text-fg-muted">Notes: {line.customization.placement_notes}</p>
    )}
    {(line.customization.reference_refs?.length ?? 0) > 0 && (
      <p className="mt-1 text-fg-subtle">{line.customization.reference_refs!.length} reference image(s) attached</p>
    )}
  </div>
)}
```

(Reference images are on the private artwork disk; rendering thumbnails needs the same signed-URL mechanism the existing artwork preview uses. If that mechanism isn't readily available on this page, showing the count + notes is sufficient for Phase 1 — staff open the refs via the existing artwork tooling.)

- [ ] **Step 3: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/QuoteDetailPage.tsx
git commit -m "feat: surface buyer-uploaded finished-look on staff quote view"
```

---

## Final verification

- [ ] **Backend suite:** `php artisan test` — all green.
- [ ] **Frontend suite:** `cd frontend && npx vitest run` — all green.
- [ ] **Typecheck:** `cd frontend && npx tsc --noEmit` — clean.
- [ ] **Manual smoke (preview server):** on a MODEL_3D product — design a logo, set qty below MOQ (blocked/clamped), switch to "Upload finished look", attach a reference + notes, add to cart, and confirm the cart line carries `mode: 'buyer_uploaded'`.

---

## Spec coverage check

- Point 1 (UI whitespace) → Group G.
- Point 2 (remove text) → Group D (frontend), server left tolerant (spec 5.2).
- MOQ (adjustable qty + superadmin minimum) → Group A (backend) + Group E (frontend).
- Point 4 (upload-finished-look fallback) → Group B (backend fields) + Group F (frontend) + Group H (staff surface); reuses the existing Proof loop (no new workflow).
- Deferred (documented): freeform auto-default into fallback (needs Phase 2/3 classification); extracting the canvas control panel fully into the rail.
