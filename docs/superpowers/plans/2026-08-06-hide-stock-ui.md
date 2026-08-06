# Hide Stock UI (Buy-Per-Order) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Remove the (always-zero, unused) stock UI from the four product-admin surfaces. Frontend-only; backend field/ledger stay dormant.

**Tech Stack:** React + TypeScript (Vitest).

Spec: `docs/superpowers/specs/2026-08-06-hide-stock-ui-design.md`
Branch: `feat/hide-stock-ui` (off master).

---

### Task 1: Variant row + single-add form (ProductAdminDetailPage)

**Files:**
- Modify: `frontend/src/pages/ProductAdminDetailPage.tsx` (`VariantsSection`, `VariantRow`)
- Test: `frontend/src/pages/ProductAdminDetailPage.test.tsx`

- [ ] **Step 1: Update the tests (red)**

In `ProductAdminDetailPage.test.tsx`, the "saves stock and price delta together" test currently asserts `stock_on_hand: 3`. Rewrite it to reflect stock being gone from the row — the save posts `price_delta` and NOT `stock_on_hand`:

```tsx
  it('saves the price delta (no stock field on the row)', async () => {
    spies.patch.mockClear();
    wrap(<VariantsSection product={withVariant} onChanged={() => {}} disabled={false} />);

    // No Stock input on the row anymore.
    expect(screen.queryByLabelText('Stock')).toBeNull();

    fireEvent.change(screen.getAllByLabelText('Price delta')[0], { target: { value: '2.5' } });
    fireEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => {
      expect(spies.patch).toHaveBeenCalledWith('/admin/variants/7', { price_delta: 2.5 });
    });
  });
```

Keep the archive test as-is. Run and confirm the rewritten test fails against current code:
Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx`
Expected: FAIL (row still has a Stock input; patch still includes `stock_on_hand`).

- [ ] **Step 2: Remove stock from `VariantRow`**

In `VariantRow`: delete the `stock` state and its Stock `<Input>`; change `onSave` to take only the delta.

New signature + body:

```tsx
function VariantRow({
  variant,
  onSave,
  onArchive,
  disabled,
}: {
  variant: AdminVariant;
  onSave: (delta: number) => void;
  onArchive: () => void;
  disabled: boolean;
}) {
  const [delta, setDelta] = useState(String(variant.price_delta));
  const label = Object.values(variant.attributes ?? {}).join(' / ') || variant.sku || `#${variant.id}`;

  return (
    <li className="flex flex-wrap items-end gap-2 rounded-md border border-border p-2">
      <span className="min-w-24 text-sm font-medium text-fg">{label}</span>
      <div className="w-28">
        <Input label="Price delta" type="number" step="0.01" value={delta} onChange={(e) => setDelta(e.target.value)} disabled={disabled} />
      </div>
      <Button size="sm" variant="outline" disabled={disabled} onClick={() => onSave(Number(delta))}>
        Save
      </Button>
      <Button size="sm" variant="ghost" disabled={disabled} onClick={onArchive}>
        Archive
      </Button>
    </li>
  );
}
```

- [ ] **Step 3: Update the section's save handler + row call**

In `VariantsSection`, change `saveVariant` to send only the delta, and update the row callback:

```tsx
  const saveVariant = async (variant: AdminVariant, delta: number) => {
    try {
      await ensureCsrf();
      await api.patch(`/admin/variants/${variant.id}`, { price_delta: delta });
      toast({ title: 'Variant saved', tone: 'success' });
      onChanged();
    } catch (err) {
      toast({ title: 'Not saved', description: apiError(err), tone: 'danger' });
    }
  };
```

Row call: `onSave={(delta) => void saveVariant(v, delta)}` (drop the stock arg).

- [ ] **Step 4: Remove stock from the single-add form**

In `VariantsSection`, delete the `variantStock` state and its Stock `<Input>`; in `addVariant`, send `stock_on_hand: 0`:

```tsx
      await api.post(`/admin/products/${product.id}/variants`, {
        attributes: { option: variantName },
        stock_on_hand: 0,
        price_delta: Number(variantDelta),
      });
```

Remove the now-unused `variantStock`/`setVariantStock` and the `setVariantStock('')` reset line.

- [ ] **Step 5: Run tests + typecheck**

Run: `cd frontend && npx vitest run src/pages/ProductAdminDetailPage.test.tsx` (expect PASS), then `npx tsc --noEmit` (expect EXIT 0).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/ProductAdminDetailPage.tsx frontend/src/pages/ProductAdminDetailPage.test.tsx
git commit -m "feat(catalogue): remove stock from variant row + add form (buy-per-order)"
```

---

### Task 2: List sort + quick-view

**Files:**
- Modify: `frontend/src/pages/ProductAdminPage.tsx` (SortKey, SORT_KEYS, option)
- Modify: `frontend/src/components/ProductQuickView.tsx` (drop "In stock" row)
- Test: adjust any test asserting these (search first)

- [ ] **Step 1: Remove the stock sort**

In `frontend/src/pages/ProductAdminPage.tsx`:
- `type SortKey = 'newest' | 'most_sold' | 'name' | 'base_cost';` (drop `'stock'`).
- `const SORT_KEYS = new Set<SortKey>(['newest', 'most_sold', 'name', 'base_cost']);`
- Delete the `<option value="stock">Stock</option>` line (~445).

- [ ] **Step 2: Remove the quick-view "In stock" row**

In `frontend/src/components/ProductQuickView.tsx`, delete line ~134:
`<Row label="In stock" value={String(product.stock_total)} />`.

- [ ] **Step 3: Find + fix affected tests**

Run: `grep -rln "In stock\|value=\"stock\"\|sort.*stock" frontend/src --include=*.test.tsx`
Update or remove any assertion referencing the removed sort option or the "In stock" row.

- [ ] **Step 4: Typecheck + full frontend suite**

Run: `cd frontend && npx tsc --noEmit` (expect 0), then `npx vitest run` (expect PASS).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/ProductAdminPage.tsx frontend/src/components/ProductQuickView.tsx
git commit -m "feat(catalogue): drop stock sort + quick-view stock row (buy-per-order)"
```

---

## Self-Review Notes

- **Spec coverage:** variant row (T1 s2-3), add form (T1 s4), list sort (T2 s1), quick-view (T2 s2). All 4 surfaces.
- **Backend untouched:** `storeVariant` still gets `stock_on_hand: 0`; `updateVariant` gets `price_delta` only (stock `sometimes`, so unchanged). No API/DB change.
- **Type consistency:** `VariantRow.onSave(delta: number)` matches the `saveVariant(v, delta)` call; PATCH body `{price_delta}` matches.
