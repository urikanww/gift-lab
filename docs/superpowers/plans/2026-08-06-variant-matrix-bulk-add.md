# Variant Matrix Bulk-Add Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff create many variants at once from size/colour axes — one bulk call that generates the cross-product as combined-label variants at stock 0.

**Architecture:** A new bulk endpoint creates a list of variants the frontend builds; a pure JS helper generates the combo labels; the Variants section grows a matrix panel with a live preview. Combined-label shape (`attributes.option`) — no downstream change.

**Tech Stack:** Laravel 11 (Pest); React + TypeScript (Vitest).

Spec: `docs/superpowers/specs/2026-08-06-variant-matrix-bulk-add-design.md`
Branch: `feat/variant-matrix-bulk-add` (off master).

---

### Task 1: Backend bulk endpoint

**Files:**
- Create: `app/Http/Requests/StoreVariantsBulkRequest.php`
- Modify: `app/Http/Controllers/AdminProductController.php` (extract `makeVariant`, add `storeVariantsBulk`)
- Modify: `routes/api.php` (near the existing `variants` routes, ~line 302)
- Test: `tests/Feature/VariantBulkAddTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VariantBulkAddTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
});

it('bulk-creates variants at stock 0 with no stock movement', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [
            ['option' => 'S / Black', 'price_delta' => 0],
            ['option' => 'S / White', 'price_delta' => 0],
            ['option' => 'M / Black', 'price_delta' => 1.5],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.created', 3)
        ->assertJsonPath('data.skipped', 0);

    expect(Variant::query()->where('product_id', $this->product->id)->count())->toBe(3)
        ->and((int) Variant::query()->where('product_id', $this->product->id)->sum('stock_on_hand'))->toBe(0)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('skips options that already exist, case-insensitively', function (): void {
    Sanctum::actingAs($this->staff);
    Variant::factory()->create(['product_id' => $this->product->id, 'attributes' => ['option' => 'S / Black']]);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [
            ['option' => 's / black'],
            ['option' => 'M / Black'],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.created', 1)
        ->assertJsonPath('data.skipped', 1);

    expect(Variant::query()->where('product_id', $this->product->id)->count())->toBe(2);
});

it('de-dupes duplicate options within one request', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [['option' => 'M / Black'], ['option' => 'M / Black']],
    ])
        ->assertOk()
        ->assertJsonPath('data.created', 1);
});

it('rejects an empty list and an over-cap list', function (): void {
    Sanctum::actingAs($this->staff);

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", ['variants' => []])
        ->assertStatus(422);

    $tooMany = array_map(fn (int $i): array => ['option' => "V{$i}"], range(1, 201));
    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", ['variants' => $tooMany])
        ->assertStatus(422);
});

it('403s a non-staff user', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => 'buyer']));

    $this->postJson("/api/admin/products/{$this->product->id}/variants/bulk", [
        'variants' => [['option' => 'M / Black']],
    ])->assertForbidden();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/VariantBulkAddTest.php`
Expected: FAIL — route 404.

- [ ] **Step 3: Create the Form Request**

Create `app/Http/Requests/StoreVariantsBulkRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreVariantsBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStaff() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'variants' => ['required', 'array', 'min:1', 'max:200'],
            'variants.*.option' => ['required', 'string', 'max:100'],
            'variants.*.price_delta' => ['nullable', 'numeric'],
        ];
    }
}
```

- [ ] **Step 4: Extract `makeVariant` and add `storeVariantsBulk`**

In `app/Http/Controllers/AdminProductController.php`, refactor `storeVariant` so the row build is shared, then add the bulk action. Add `use App\Http\Requests\StoreVariantsBulkRequest;` at the top.

Replace the `Variant::create([...])` block inside `storeVariant` with a call to the new helper (keep its ledger `Init` for opening stock), and add both methods:

```php
    private function makeVariant(Product $product, array $attributes, ?string $sku, int $reorderThreshold, float $priceDelta): Variant
    {
        // Always created at zero; callers seed opening stock through the ledger
        // so cached stock_on_hand always equals SUM(delta), never a direct write.
        return Variant::create([
            'product_id' => $product->id,
            'attributes' => $attributes,
            'sku' => $sku,
            'stock_on_hand' => 0,
            'reorder_threshold' => $reorderThreshold,
            'price_delta' => $priceDelta,
            'currency' => 'SGD',
        ]);
    }

    public function storeVariantsBulk(StoreVariantsBulkRequest $request, Product $product): JsonResponse
    {
        $rows = $request->validated()['variants'];

        // Existing labels + within-request duplicates are skipped (case-insensitive)
        // so re-running never manufactures a duplicate variant.
        $seen = $product->variants()->get()
            ->map(fn (Variant $v): string => mb_strtolower((string) ($v->attributes['option'] ?? '')))
            ->filter()
            ->flip();

        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($product, $rows, $seen, &$created, &$skipped): void {
            foreach ($rows as $row) {
                $option = trim((string) $row['option']);
                $key = mb_strtolower($option);

                if ($option === '' || $seen->has($key)) {
                    $skipped++;

                    continue;
                }

                $seen->put($key, true);
                $this->makeVariant($product, ['option' => $option], null, 0, (float) ($row['price_delta'] ?? 0));
                $created++;
            }
        });

        $this->audit->log($product, 'variant.bulk_created', null, [
            'product_id' => $product->id,
            'created' => $created,
            'skipped' => $skipped,
        ]);

        return response()->json(['data' => ['created' => $created, 'skipped' => $skipped]]);
    }
```

In `storeVariant`, replace its inline `Variant::create([...])` with:

```php
        $variant = $this->makeVariant(
            $product,
            $validated['attributes'],
            $validated['sku'] ?? null,
            $validated['reorder_threshold'] ?? 0,
            (float) ($validated['price_delta'] ?? 0),
        );

        if ($validated['stock_on_hand'] > 0) {
            $this->ledger->record($variant, $validated['stock_on_hand'], StockMovementReason::Init);
        }
```

(Confirm `JsonResponse` and `DB` are already imported in the controller — they are, used by sibling methods.)

- [ ] **Step 5: Add the route**

In `routes/api.php`, next to the existing variant routes (~line 302):

```php
    Route::post('/admin/products/{product}/variants/bulk', [AdminProductController::class, 'storeVariantsBulk'])->middleware('permission:products.edit');
```

- [ ] **Step 6: Run to verify it passes**

Run: `php artisan test tests/Feature/VariantBulkAddTest.php`
Expected: PASS (5 tests). Also run `php artisan test --filter=Variant` to confirm the `storeVariant` refactor didn't regress its own tests.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/StoreVariantsBulkRequest.php app/Http/Controllers/AdminProductController.php routes/api.php tests/Feature/VariantBulkAddTest.php
git commit -m "feat(catalogue): bulk variant create endpoint (dedup, stock 0)"
```

---

### Task 2: Combo-generator helper

**Files:**
- Create: `frontend/src/lib/variantMatrix.ts`
- Test: `frontend/src/lib/variantMatrix.test.ts`

- [ ] **Step 1: Write the failing test**

Create `frontend/src/lib/variantMatrix.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { generateVariantLabels } from './variantMatrix';

describe('generateVariantLabels', () => {
  it('cross-products two axes in order', () => {
    expect(generateVariantLabels([{ values: 'S, M' }, { values: 'Black, White' }])).toEqual([
      'S / Black',
      'S / White',
      'M / Black',
      'M / White',
    ]);
  });

  it('returns bare values for a single axis', () => {
    expect(generateVariantLabels([{ values: 'Red, Blue' }])).toEqual(['Red', 'Blue']);
  });

  it('trims, drops blanks, and de-dupes values case-insensitively per axis', () => {
    expect(generateVariantLabels([{ values: ' S , s ,  , M ' }])).toEqual(['S', 'M']);
  });

  it('ignores axes with no usable values', () => {
    expect(generateVariantLabels([{ values: 'S, M' }, { values: '  ' }])).toEqual(['S', 'M']);
  });

  it('returns an empty list when nothing is entered', () => {
    expect(generateVariantLabels([{ values: '' }])).toEqual([]);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend && npx vitest run src/lib/variantMatrix.test.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement the helper**

Create `frontend/src/lib/variantMatrix.ts`:

```ts
export interface VariantAxis {
  /** Display name (label-only; not stored). */
  name?: string;
  /** Comma-separated values, e.g. "S, M, L". */
  values: string;
}

/** Parse one axis' comma list: trim, drop blanks, de-dupe case-insensitively, keep order. */
function parseAxis(values: string): string[] {
  const seen = new Set<string>();
  const out: string[] = [];
  for (const raw of values.split(',')) {
    const v = raw.trim();
    if (v === '') continue;
    const key = v.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(v);
  }
  return out;
}

/**
 * Cross-product the axes into combined labels ("M / Black"). A single axis
 * yields bare values. Empty axes are ignored; no values anywhere → [].
 */
export function generateVariantLabels(axes: VariantAxis[]): string[] {
  const lists = axes.map((a) => parseAxis(a.values)).filter((l) => l.length > 0);
  if (lists.length === 0) return [];

  return lists.reduce<string[]>(
    (acc, list) => acc.flatMap((prefix) => list.map((v) => (prefix ? `${prefix} / ${v}` : v))),
    [''],
  );
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd frontend && npx vitest run src/lib/variantMatrix.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/variantMatrix.ts frontend/src/lib/variantMatrix.test.ts
git commit -m "feat(catalogue): variant matrix combo-label generator"
```

---

### Task 3: Matrix panel in the Variants section

**Files:**
- Modify: `frontend/src/pages/ProductAdminDetailPage.tsx` (`VariantsSection`)
- Test: `frontend/src/pages/ProductAdminDetailPage.test.tsx` (extend)

- [ ] **Step 1: Write the failing test**

Extend `frontend/src/pages/ProductAdminDetailPage.test.tsx`. `VariantsSection` is currently module-private — export it (`export function VariantsSection`) so it can mount in isolation, matching the already-exported `EditForm`. Add:

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import { VariantsSection } from './ProductAdminDetailPage';
import { ToastProvider } from '../ui';
import api from '../lib/api';
import type { AdminProduct } from '../types';

vi.mock('../lib/api', async (orig) => {
  const mod = (await orig()) as Record<string, unknown>;
  return { ...mod, default: { ...(mod.default as object), post: vi.fn().mockResolvedValue({ data: { data: { created: 6, skipped: 0 } } }) } };
});

const product = { id: 1, name: 'Mug', class: 'SCRAPED_UV', variants: [] } as unknown as AdminProduct;

function mount() {
  return render(
    <MemoryRouter><ToastProvider>
      <VariantsSection product={product} onChanged={() => {}} disabled={false} />
    </ToastProvider></MemoryRouter>,
  );
}

describe('VariantsSection matrix bulk-add', () => {
  it('previews the cross-product and posts it to the bulk endpoint', async () => {
    mount();
    const values = screen.getAllByLabelText(/values/i);
    fireEvent.change(values[0], { target: { value: 'S, M, L' } });
    fireEvent.change(values[1], { target: { value: 'Black, White' } });

    expect(await screen.findByText(/will create 6 variants/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /create 6 variants/i }));

    const post = (api as unknown as { post: ReturnType<typeof vi.fn> }).post;
    expect(post).toHaveBeenCalledWith(
      '/admin/products/1/variants/bulk',
      expect.objectContaining({
        variants: expect.arrayContaining([expect.objectContaining({ option: 'S / Black' })]),
      }),
    );
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx`
Expected: FAIL — `VariantsSection` not exported / no matrix UI.

- [ ] **Step 3: Add the matrix panel**

In `frontend/src/pages/ProductAdminDetailPage.tsx`: `import { generateVariantLabels, type VariantAxis } from '../lib/variantMatrix';` and export the component (`export function VariantsSection`).

Inside `VariantsSection`, add state + handlers:

```tsx
  const [axes, setAxes] = useState<VariantAxis[]>([
    { name: 'Size', values: '' },
    { name: 'Colour', values: '' },
  ]);
  const [bulkDelta, setBulkDelta] = useState('0');
  const [bulkBusy, setBulkBusy] = useState(false);
  const labels = generateVariantLabels(axes);

  const setAxis = (i: number, patch: Partial<VariantAxis>) =>
    setAxes((a) => a.map((ax, j) => (j === i ? { ...ax, ...patch } : ax)));

  const bulkAdd = async () => {
    if (bulkBusy || labels.length === 0) return;
    setBulkBusy(true);
    try {
      await ensureCsrf();
      const { data } = await api.post<{ data: { created: number; skipped: number } }>(
        `/admin/products/${product.id}/variants/bulk`,
        { variants: labels.map((option) => ({ option, price_delta: Number(bulkDelta) })) },
      );
      const { created, skipped } = data.data;
      toast({
        title: `Created ${created} variant${created === 1 ? '' : 's'}`,
        description: skipped > 0 ? `${skipped} skipped, already existed` : undefined,
        tone: 'success',
      });
      setAxes((a) => a.map((ax) => ({ ...ax, values: '' })));
      onChanged();
    } catch (err) {
      toast({ title: 'Bulk add failed', description: apiError(err), tone: 'danger' });
    } finally {
      setBulkBusy(false);
    }
  };
```

Render the panel above the existing single-add `<form>` (only when `!disabled`): an axis row per `axes` entry (a `name` input, a `values` input with an accessible label containing "values", and a remove-axis button that splices `axes`), an "add axis" button (`setAxes((a) => [...a, { name: '', values: '' }])`), a "price delta (all)" input bound to `bulkDelta`, a preview block rendering `will create {labels.length} variants` + the labels as pills, and a submit button labelled `create {labels.length} variants` (disabled when `labels.length === 0 || labels.length > 200 || bulkBusy`) calling `void bulkAdd()`. Follow the mockup in the spec for layout; reuse `Input`/`Button` from `../ui`.

- [ ] **Step 4: Run to verify it passes**

Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Typecheck + full frontend suite**

Run: `cd frontend && npx tsc --noEmit` (expect EXIT 0), then `cd frontend && npx vitest run` (expect PASS).

- [ ] **Step 6: Verify in the preview**

Start the dev server, open a SCRAPED_UV product with no variants, enter two axes, confirm the preview count matches and "create N variants" adds them (they appear in the list at stock 0). Screenshot.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/ProductAdminDetailPage.tsx frontend/src/pages/ProductAdminDetailPage.test.tsx
git commit -m "feat(catalogue): matrix bulk-add panel in the variants section"
```

---

## Self-Review Notes

- **Spec coverage:** bulk endpoint + dedup + stock 0 + cap (Task 1), combo generator (Task 2), matrix panel + preview + wiring (Task 3). `makeVariant` reuse in Task 1 keeps `storeVariant`'s ledger behaviour.
- **Types consistent:** `generateVariantLabels(axes: VariantAxis[])` and `VariantAxis {name?, values}` used identically in Tasks 2 and 3; endpoint path `/admin/products/{id}/variants/bulk` and payload `{variants:[{option, price_delta}]}` identical in Tasks 1 and 3; response `{data:{created,skipped}}` identical.
- **No placeholders:** the only prose-described piece is the panel JSX layout in Task 3 step 3 (explicit element list + state/handlers given); everything else is complete code.
