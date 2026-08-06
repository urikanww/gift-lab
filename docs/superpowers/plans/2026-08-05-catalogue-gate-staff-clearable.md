# Catalogue Publish-Gate: Staff-Clearable Blockers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every publish blocker fixable by staff (add the missing `is_printable` toggle), drop the dead `stock_unreadable` check, and default new products to buy-per-order.

**Architecture:** Three small, independent changes: a service edit (`CompletenessGate`), a model-level default (`Product`), and a frontend form field (`ProductAdminDetailPage`). No new endpoints — the backend already accepts `is_printable`.

**Tech Stack:** Laravel 11 (PHP), Pest tests; React + TypeScript frontend, Vitest.

Spec: `docs/superpowers/specs/2026-08-05-catalogue-gate-staff-clearable-design.md`

---

### Task 1: Remove `stock_unreadable` from the completeness gate

**Files:**
- Modify: `app/Services/Catalogue/CompletenessGate.php:36-49`
- Test: `tests/Feature/CompletenessGateStockModeTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompletenessGateStockModeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\StockMode;
use App\Models\Product;
use App\Services\Catalogue\CompletenessGate;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('never returns stock_unreadable, even for a stocked product with no estimate', function (): void {
    $product = Product::factory()->create([
        'stock_mode' => StockMode::Stocked->value,
        'stock_estimate' => null,
        'base_cost' => 5.00,
        'weight' => 0.2,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10],
        'is_printable' => true,
        'print_method' => 'UV',
    ]);

    $reasons = app(CompletenessGate::class)->reasons($product);

    expect($reasons)->not->toContain('stock_unreadable');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/CompletenessGateStockModeTest.php`
Expected: FAIL — `reasons` still contains `stock_unreadable`.

- [ ] **Step 3: Delete the `stock_unreadable` branch**

In `app/Services/Catalogue/CompletenessGate.php`, remove these lines (the `stock_mode` block at ~44):

```php
        // Only STOCKED blanks need a readable stock estimate. A MAKE_TO_ORDER
        // (buy-per-order) blank has no seller quantity to read - a null estimate
        // is expected there, checked by a human at procurement - so it must not
        // block publish; STOCKED items still gate on it.
        if ($product->stock_mode === StockMode::Stocked && $product->stock_estimate === null) {
            $reasons[] = 'stock_unreadable';
        }
```

Also remove the now-unused `use App\Enums\StockMode;` import at the top, and update the class docblock reason list (line ~13) to drop `stock_unreadable`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/CompletenessGateStockModeTest.php`
Expected: PASS.

- [ ] **Step 5: Run the wider catalogue suite for regressions**

Run: `php artisan test --filter=Catalogue`
Expected: PASS (no test asserted `stock_unreadable` was produced; if one does, update it to reflect the removal).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Catalogue/CompletenessGate.php tests/Feature/CompletenessGateStockModeTest.php
git commit -m "feat(catalogue): drop dead stock_unreadable publish blocker"
```

---

### Task 2: Default new products to buy-per-order

**Files:**
- Modify: `app/Models/Product.php:37-105` (add `$attributes` default)
- Test: `tests/Feature/ProductDefaultStockModeTest.php` (create)

Rationale: the products table column defaults to `STOCKED` at the DB level. Rather than an ALTER (needs doctrine/dbal), set the model-level default so every Eloquent-created product without an explicit mode is `MAKE_TO_ORDER`. Import services already set it explicitly, so they are unaffected.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ProductDefaultStockModeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\StockMode;
use App\Models\Product;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('defaults a new product to MAKE_TO_ORDER when stock_mode is not given', function (): void {
    $product = new Product();

    expect($product->stock_mode)->toBe(StockMode::MakeToOrder);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ProductDefaultStockModeTest.php`
Expected: FAIL — `stock_mode` is null (or `STOCKED` after DB insert).

- [ ] **Step 3: Add the model-level default**

In `app/Models/Product.php`, add an `$attributes` property below the `$fillable` array:

```php
    /**
     * Buy-per-order is the business default (no held stock). The DB column still
     * defaults to STOCKED for legacy rows; this makes every Eloquent-created
     * product buy-per-order unless an importer sets it explicitly.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock_mode' => 'MAKE_TO_ORDER',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/ProductDefaultStockModeTest.php`
Expected: PASS.

- [ ] **Step 5: Guard the factory default**

The factory sets `'stock_mode' => 'STOCKED'` (`database/factories/ProductFactory.php:30`). Leave it — tests that want stocked rely on it, and the two `MAKE_TO_ORDER` states at :44/:64 are explicit. No change. Run the product suite:

Run: `php artisan test --filter=Product`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Product.php tests/Feature/ProductDefaultStockModeTest.php
git commit -m "feat(catalogue): default new products to buy-per-order stock mode"
```

---

### Task 3: Add the `is_printable` toggle to the product edit form

**Files:**
- Modify: `frontend/src/pages/ProductAdminDetailPage.tsx:422-489` (EditForm state + payload) and the form's JSX (near the `print_method` control, ~line 475)
- Test: `frontend/src/pages/ProductAdminDetailPage.test.tsx` (create or extend)

The backend already accepts `is_printable` (`AdminProductController` `PRODUCT_RULES`). `AdminProduct` type must expose `is_printable` — confirm it exists in `frontend/src/types.ts`; if absent, add `is_printable?: boolean;` to the `AdminProduct` interface.

- [ ] **Step 1: Confirm the type field**

Run: `grep -n "is_printable" frontend/src/types.ts`
If no match, add `is_printable?: boolean;` to the `AdminProduct` interface.

- [ ] **Step 2: Write the failing test**

Create/extend `frontend/src/pages/ProductAdminDetailPage.test.tsx` with a test that the edit form renders a printable toggle and submits it. Minimal render test:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { EditForm } from './ProductAdminDetailPage';

describe('EditForm printable toggle', () => {
  it('renders an is_printable checkbox seeded from the product', () => {
    const product = {
      id: 1, name: 'Mug', class: 'SCRAPED_UV', base_cost: '5.00',
      print_method: 'UV', stock_mode: 'MAKE_TO_ORDER', is_printable: false,
      dimensions: { l: 10, w: 10, h: 10 }, weight: '0.2',
    } as never;
    render(<EditForm product={product} onChanged={() => {}} />);
    const toggle = screen.getByLabelText(/printable/i) as HTMLInputElement;
    expect(toggle.checked).toBe(false);
  });
});
```

Note: `EditForm` is currently a module-private function. Export it (`export function EditForm`) so the test can mount it in isolation.

- [ ] **Step 3: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx`
Expected: FAIL — no element labelled "printable"; and/or `EditForm` not exported.

- [ ] **Step 4: Add the state, control, and payload field**

In `EditForm` (`ProductAdminDetailPage.tsx`), add state near the other `useState` calls (~line 442):

```tsx
  const [isPrintable, setIsPrintable] = useState<boolean>(Boolean(product.is_printable));
```

Add the field to the `payload` object (~line 480, alongside `stock_mode`):

```tsx
      is_printable: isPrintable,
```

Add the control in the JSX near the `print_method` select (~line 475):

```tsx
      <label className="flex items-center gap-2">
        <input
          type="checkbox"
          checked={isPrintable}
          onChange={(e) => setIsPrintable(e.target.checked)}
        />
        <span>Printable (required to publish)</span>
      </label>
```

Export the function: change `function EditForm(` to `export function EditForm(`.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx`
Expected: PASS.

- [ ] **Step 6: Verify in the preview**

Start the dev server, open a product with the printable flag off, toggle it on + set a print method, save, and confirm the `not_printable` blocker clears (product becomes publishable). Capture a screenshot.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/ProductAdminDetailPage.tsx frontend/src/pages/ProductAdminDetailPage.test.tsx frontend/src/types.ts
git commit -m "feat(catalogue): staff-editable printable toggle to clear not_printable blocker"
```

---

## Self-Review Notes

- Spec coverage: `stock_unreadable` removal (Task 1), stock-mode default (Task 2), `is_printable` toggle (Task 3), MOQ default already 1 (no task needed). `not_printable` gate kept intact — verified no task removes it.
- The `is_printable + print_method` gate logic in `CompletenessGate` is untouched; only the field's editability changes.
