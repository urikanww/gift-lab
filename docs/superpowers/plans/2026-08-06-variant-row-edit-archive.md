# Variant Row: Editable Delta + Archive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let staff edit a variant's price delta and archive (soft-delete) a variant from its row.

**Architecture:** Backend gains a soft-delete archive endpoint (delta editing already works via the existing PATCH). The Variants row grows a delta input (saved alongside stock) and an Archive button with a confirm.

**Tech Stack:** Laravel 11 (Pest); React + TypeScript (Vitest).

Spec: `docs/superpowers/specs/2026-08-06-variant-row-edit-archive-design.md`
Branch: `feat/variant-row-edit-archive` (off master).

---

### Task 1: Backend archive endpoint (+ delta-update coverage)

**Files:**
- Modify: `app/Http/Controllers/AdminProductController.php` (add `archiveVariant`)
- Modify: `routes/api.php` (DELETE route by the other variant routes)
- Test: `tests/Feature/VariantArchiveTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VariantArchiveTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use App\Services\QuoteService;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
});

it('soft-deletes a variant: gone from the product, still resolvable for past orders', function (): void {
    Sanctum::actingAs($this->staff);
    $variant = Variant::factory()->create(['product_id' => $this->product->id, 'attributes' => ['option' => 'M']]);
    $quote = Quote::factory()->create();
    $line = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $this->product->id, 'variant_id' => $variant->id]);

    $this->deleteJson("/api/admin/variants/{$variant->id}")
        ->assertOk()
        ->assertJsonPath('data.archived', true);

    expect($this->product->variants()->count())->toBe(0)
        ->and(Variant::withTrashed()->find($variant->id))->not->toBeNull()
        ->and($line->fresh()->variant()->withTrashed()->first()?->id)->toBe($variant->id);
});

it('403s a non-staff user archiving a variant', function (): void {
    $variant = Variant::factory()->create(['product_id' => $this->product->id]);
    Sanctum::actingAs(User::factory()->create(['role' => 'buyer']));

    $this->deleteJson("/api/admin/variants/{$variant->id}")->assertForbidden();
});

it('updates a variant price delta with no stock movement when stock is unchanged', function (): void {
    Sanctum::actingAs($this->staff);
    $variant = Variant::factory()->create(['product_id' => $this->product->id, 'price_delta' => 0, 'stock_on_hand' => 0]);

    $this->patchJson("/api/admin/variants/{$variant->id}", ['price_delta' => 2.5])
        ->assertOk();

    expect((float) $variant->fresh()->price_delta)->toBe(2.5)
        ->and(\App\Models\StockMovement::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test tests/Feature/VariantArchiveTest.php`
Expected: FAIL — DELETE route 404 (the delta-update test may already pass).

- [ ] **Step 3: Add the controller action**

In `app/Http/Controllers/AdminProductController.php`, add (near `updateVariant`):

```php
    public function archiveVariant(Request $request, Variant $variant): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $label = implode(' / ', array_values((array) $variant->attributes)) ?: ($variant->sku ?? "#{$variant->id}");

        $variant->delete();

        $this->audit->log($variant, 'variant.archived', null, [
            'product_id' => $variant->product_id,
            'label' => $label,
        ]);

        return response()->json(['data' => ['archived' => true]]);
    }
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, next to the other `variants` routes:

```php
    Route::delete('/admin/variants/{variant}', [AdminProductController::class, 'archiveVariant'])->middleware('permission:products.edit');
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test tests/Feature/VariantArchiveTest.php`
Expected: PASS (3 tests). Then `php artisan test --filter=Variant` to confirm no regression.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminProductController.php routes/api.php tests/Feature/VariantArchiveTest.php
git commit -m "feat(catalogue): archive (soft-delete) a variant endpoint"
```

---

### Task 2: Editable delta + archive button in the row

**Files:**
- Modify: `frontend/src/pages/ProductAdminDetailPage.tsx` (`VariantsSection` save handler, `VariantRow`, history labels)
- Test: `frontend/src/pages/ProductAdminDetailPage.test.tsx` (extend)

- [ ] **Step 1: Write the failing test**

Extend `frontend/src/pages/ProductAdminDetailPage.test.tsx`. The api mock is already present; the product fixture needs a variant. Add:

```tsx
describe('VariantRow edit + archive', () => {
  const withVariant = {
    ...product,
    variants: [{ id: 7, attributes: { option: 'M' }, stock_on_hand: 3, price_delta: '1.00', sku: null }],
  } as unknown as AdminProduct;

  it('saves stock and price delta together', async () => {
    const post = (api as unknown as { post: ReturnType<typeof vi.fn>; patch: ReturnType<typeof vi.fn> });
    post.patch = vi.fn().mockResolvedValue({ data: { data: {} } });
    wrap(<VariantsSection product={withVariant} onChanged={() => {}} disabled={false} />);

    fireEvent.change(screen.getByLabelText(/price delta/i, { selector: 'input' }), { target: { value: '2.5' } });
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() =>
      expect(post.patch).toHaveBeenCalledWith(
        '/admin/variants/7',
        expect.objectContaining({ stock_on_hand: 3, price_delta: 2.5 }),
      ),
    );
  });

  it('archives a variant after confirm', async () => {
    const del = vi.fn().mockResolvedValue({ data: { data: { archived: true } } });
    (api as unknown as { delete: unknown }).delete = del;
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    wrap(<VariantsSection product={withVariant} onChanged={() => {}} disabled={false} />);

    fireEvent.click(screen.getByRole('button', { name: /archive/i }));

    await waitFor(() => expect(del).toHaveBeenCalledWith('/admin/variants/7'));
  });
});
```

Note: the shared `api` mock in this file has only `post`. Extend the top `vi.mock('../lib/api', …)` default to also include `patch: vi.fn()` and `delete: vi.fn()` so the row handlers resolve. (Adjust the two new tests to read those spies.)

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx`
Expected: FAIL — no price-delta input / no archive button.

- [ ] **Step 3: Update the section handler + row**

In `VariantsSection`, replace `updateStock` with a combined save and add an archive handler:

```tsx
  const saveVariant = async (variant: AdminVariant, stock: number, delta: number) => {
    try {
      await ensureCsrf();
      await api.patch(`/admin/variants/${variant.id}`, { stock_on_hand: stock, price_delta: delta });
      toast({ title: 'Variant saved', tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not saved', description: apiError(err), tone: 'danger' });
    }
  };

  const archiveVariant = async (variant: AdminVariant) => {
    if (!window.confirm('Archive this variant? Past orders keep it; it can’t be ordered again.')) return;
    try {
      await ensureCsrf();
      await api.delete(`/admin/variants/${variant.id}`);
      toast({ title: 'Variant archived', tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not archived', description: apiError(err), tone: 'danger' });
    }
  };
```

Update the row render to pass the new callbacks:

```tsx
            <VariantRow
              key={v.id}
              variant={v}
              disabled={disabled}
              onSave={(stock, delta) => void saveVariant(v, stock, delta)}
              onArchive={() => void archiveVariant(v)}
            />
```

Rewrite `VariantRow`:

```tsx
function VariantRow({
  variant,
  onSave,
  onArchive,
  disabled,
}: {
  variant: AdminVariant;
  onSave: (stock: number, delta: number) => void;
  onArchive: () => void;
  disabled: boolean;
}) {
  const [stock, setStock] = useState(String(variant.stock_on_hand));
  const [delta, setDelta] = useState(String(variant.price_delta));
  const label = Object.values(variant.attributes ?? {}).join(' / ') || variant.sku || `#${variant.id}`;

  return (
    <li className="flex flex-wrap items-end gap-2 rounded-md border border-border p-2">
      <span className="min-w-24 text-sm font-medium text-fg">{label}</span>
      <div className="w-24">
        <Input label="Stock" type="number" min="0" value={stock} onChange={(e) => setStock(e.target.value)} disabled={disabled} />
      </div>
      <div className="w-28">
        <Input label="Price delta" type="number" step="0.01" value={delta} onChange={(e) => setDelta(e.target.value)} disabled={disabled} />
      </div>
      <Button size="sm" variant="outline" disabled={disabled} onClick={() => onSave(Number(stock), Number(delta))}>
        Save
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={onArchive}>
        Archive
      </Button>
    </li>
  );
}
```

- [ ] **Step 4: Add the history label**

In `HISTORY_EVENT_LABELS`, add: `'variant.archived': 'Variant archived',`.

- [ ] **Step 5: Run to verify it passes + typecheck**

Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx` (expect PASS), then `npx tsc --noEmit` (expect EXIT 0).

- [ ] **Step 6: Full frontend suite**

Run: `cd frontend && npx vitest run`
Expected: PASS. (The old test asserting a "Save stock" button, if any, is replaced by the new "Save".)

- [ ] **Step 7: Commit**

```bash
git add frontend/src/pages/ProductAdminDetailPage.tsx frontend/src/pages/ProductAdminDetailPage.test.tsx
git commit -m "feat(catalogue): editable price delta + archive in the variant row"
```

---

## Self-Review Notes

- **Spec coverage:** archive endpoint + soft-delete + audit + 403 (Task 1); delta-update coverage (Task 1); editable delta input + combined save + archive button + confirm + history label (Task 2). All spec items covered.
- **No backend change for delta editing** — `updateVariant` already accepts `price_delta`; Task 1 only adds a test for it.
- **Types consistent:** `onSave(stock, delta)` / `onArchive()` on `VariantRow` match the `VariantsSection` callbacks; PATCH body `{stock_on_hand, price_delta}` and `DELETE /admin/variants/{id}` match Task 1's endpoint.
- **Confirm is `window.confirm`** — matches "lightweight confirm" in the spec; the archive test stubs it.
