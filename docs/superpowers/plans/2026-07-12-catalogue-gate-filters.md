# Catalogue Gate Advanced Filters — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the catalogue gate a Filters modal with removable chips (matching ProductAdminPage), URL-param filter state, and production-focused filters — blocker reason, source, print method, category, IP-flagged, missing buy link.

**Architecture:** Persist a derived `source_kind` on products for an indexed source filter. Extend `AdminCatalogueController::index` with the new params (applied to both the paginator and the `$byState` counts). Extract the shared chips/pill UI from ProductAdminPage into a reusable component and consume it on both admin pages; migrate the gate's filter state to URL params.

**Tech Stack:** Laravel 11 (PHP 8.3), Pest; React + TypeScript, Vitest, react-router `useSearchParams`.

**Assumes:** [UV Blank Library Phase 1](2026-07-12-uv-blank-library-phase1.md) **Task 1 (the `source_links` column) is already applied** — the *missing buy link* and *source* filters read it. Run that task first (per the agreed order: Phase 1, then filters).

**Design:** [catalogue-gate-filters-design.md](../specs/2026-07-12-catalogue-gate-filters-design.md)

**Commit trailer:** every commit message ends with a second `-m` paragraph:
`Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`

---

## File structure

**Backend (create):**
- `app/Support/SourceKind.php` — pure `fromUrl(?string): string` → `marketplace|local|makerworld|thingiverse|cults3d|manual`.
- `database/migrations/2026_07_12_000002_add_source_kind_to_products.php` — column + index + backfill.
- `tests/Unit/SourceKindTest.php`, `tests/Feature/AdminCatalogueFiltersTest.php`.

**Backend (modify):**
- `app/Models/Product.php` — fillable `source_kind`; saving hook derives it from `source_url`.
- `app/Http/Controllers/AdminCatalogueController.php:40-130` — new filter params (paginator + `$byState`), return `source_kind` in the row payload.

**Frontend (create):**
- `frontend/src/components/admin/Filters.tsx` — `CountPill`, `FilterChips`.
- `frontend/src/components/admin/Filters.test.tsx` — Vitest.
- `frontend/src/lib/sourceKind.ts` — label map + type (frontend mirror).

**Frontend (modify):**
- `frontend/src/pages/ProductAdminPage.tsx` — use shared `CountPill` + `FilterChips` (behaviour unchanged).
- `frontend/src/pages/CatalogueAdminPage.tsx` — URL-param filters + Filters modal + chips + new fields.
- `frontend/src/stores/catalogueAdminStore.ts:29-33` — extend the filter param type.
- `frontend/src/types.ts` — add `source_kind` to `AdminCatalogueItem`.

---

## Task 1: `SourceKind` helper + `source_kind` column

**Files:**
- Create: `app/Support/SourceKind.php`, `tests/Unit/SourceKindTest.php`
- Create: `database/migrations/2026_07_12_000002_add_source_kind_to_products.php`
- Modify: `app/Models/Product.php`

- [ ] **Step 1: Write the failing helper test**

`tests/Unit/SourceKindTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\SourceKind;

it('classifies known marketplaces', function (): void {
    expect(SourceKind::fromUrl('https://shopee.sg/product/1/2'))->toBe('marketplace');
    expect(SourceKind::fromUrl('https://www.lazada.sg/products/x.html'))->toBe('marketplace');
});

it('classifies 3D model sources', function (): void {
    expect(SourceKind::fromUrl('https://makerworld.com/en/models/3015782'))->toBe('makerworld');
    expect(SourceKind::fromUrl('https://www.thingiverse.com/thing:123'))->toBe('thingiverse');
    expect(SourceKind::fromUrl('https://cults3d.com/en/x'))->toBe('cults3d');
});

it('classifies empty as manual and anything else as local', function (): void {
    expect(SourceKind::fromUrl(null))->toBe('manual');
    expect(SourceKind::fromUrl(''))->toBe('manual');
    expect(SourceKind::fromUrl('https://blankco.sg/mug'))->toBe('local');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/SourceKindTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the helper**

`app/Support/SourceKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalise a product's source_url host into a small, filterable label. Persisted
 * on products.source_kind so the catalogue gate can filter/display by provenance
 * without re-parsing URLs at query time.
 */
final class SourceKind
{
    public const ALL = ['marketplace', 'local', 'makerworld', 'thingiverse', 'cults3d', 'manual'];

    private const MARKETPLACE = ['shopee.', 'lazada.', 'amazon.', 'aliexpress.', 'taobao.', '1688.', 'qoo10.'];

    public static function fromUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return 'manual';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return 'manual';
        }

        if (str_contains($host, 'makerworld')) {
            return 'makerworld';
        }
        if (str_contains($host, 'thingiverse')) {
            return 'thingiverse';
        }
        if (str_contains($host, 'cults3d')) {
            return 'cults3d';
        }
        foreach (self::MARKETPLACE as $needle) {
            if (str_contains($host, $needle)) {
                return 'marketplace';
            }
        }

        return 'local';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/SourceKindTest.php`
Expected: PASS.

- [ ] **Step 5: Create the migration (column + index + backfill)**

`database/migrations/2026_07_12_000002_add_source_kind_to_products.php`:

```php
<?php

declare(strict_types=1);

use App\Support\SourceKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted, indexed source provenance label for the catalogue gate's Source
 * filter. Derived from source_url via App\Support\SourceKind and kept in sync in
 * the Product saving hook. Backfilled here for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('source_kind', 20)->nullable()->after('source_url')
                ->comment('marketplace|local|makerworld|thingiverse|cults3d|manual');
            $table->index('source_kind');
        });

        DB::table('products')->select('id', 'source_url')->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('products')->where('id', $row->id)
                        ->update(['source_kind' => SourceKind::fromUrl($row->source_url)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['source_kind']);
            $table->dropColumn('source_kind');
        });
    }
};
```

- [ ] **Step 6: Wire the model**

In `app/Models/Product.php`: add `'source_kind',` to `$fillable` (after `'source_url',`).

Add a saving hook in `booted()` **after** the source_links→source_url hook (added in Phase 1 Task 1), so it derives from the final source_url:

```php
        // Keep source_kind in sync with the (possibly just-derived) source_url so
        // the catalogue gate can filter/display by provenance without URL parsing.
        static::saving(function (Product $product): void {
            $product->source_kind = \App\Support\SourceKind::fromUrl($product->source_url);
        });
```

- [ ] **Step 7: Write a model test for the sync**

Append to `tests/Unit/SourceKindTest.php`:

```php
it('syncs source_kind on the product when saved', function (): void {
    $p = \App\Models\Product::factory()->scrapedUv()->create([
        'source_url' => 'https://shopee.sg/product/1/2',
        'source_links' => [['label' => 'S', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null]],
    ]);
    expect($p->fresh()->source_kind)->toBe('marketplace');
});
```

- [ ] **Step 8: Run tests**

Run: `php artisan test tests/Unit/SourceKindTest.php`
Expected: PASS (helper + sync).

- [ ] **Step 9: Commit**

```bash
git add app/Support/SourceKind.php app/Models/Product.php database/migrations/2026_07_12_000002_add_source_kind_to_products.php tests/Unit/SourceKindTest.php
git commit -m "feat(catalogue): persisted source_kind for provenance filtering" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Backend filters in the gate index

**Files:**
- Modify: `app/Http/Controllers/AdminCatalogueController.php:40-130`
- Test: `tests/Feature/AdminCatalogueFiltersTest.php`

New params: `blocker, source, print_method, category, ip_flagged, missing_link`. Applied to **both** the paginator and `$byState`. Row payload gains `source_kind`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AdminCatalogueFiltersTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($this->staff);
});

it('filters by blocker reason', function (): void {
    Product::factory()->scrapedUv()->create(['name' => 'NoDims', 'publish_state' => 'CANNOT_PUBLISH', 'cannot_publish_reasons' => ['missing_dimensions']]);
    Product::factory()->scrapedUv()->create(['name' => 'NoPrice', 'publish_state' => 'CANNOT_PUBLISH', 'cannot_publish_reasons' => ['missing_price']]);

    $res = $this->getJson('/api/admin/catalogue?blocker=missing_dimensions')->assertOk();
    $names = collect($res->json('data'))->pluck('name');
    expect($names)->toContain('NoDims')->not->toContain('NoPrice');
});

it('filters by source kind and returns source_kind in the payload', function (): void {
    Product::factory()->scrapedUv()->create(['name' => 'Shp', 'source_url' => 'https://shopee.sg/product/1/2']);
    Product::factory()->scrapedUv()->create(['name' => 'Loc', 'source_url' => 'https://blankco.sg/mug']);

    $res = $this->getJson('/api/admin/catalogue?source=local')->assertOk();
    $rows = collect($res->json('data'));
    expect($rows->pluck('name'))->toContain('Loc')->not->toContain('Shp')
        ->and($rows->firstWhere('name', 'Loc')['source_kind'])->toBe('local');
});

it('filters by print method', function (): void {
    Product::factory()->scrapedUv()->create(['name' => 'UvOne', 'print_method' => 'UV']);
    Product::factory()->model3d()->create(['name' => 'FdmOne', 'print_method' => 'FDM']);

    $res = $this->getJson('/api/admin/catalogue?print_method=FDM')->assertOk();
    expect(collect($res->json('data'))->pluck('name'))->toContain('FdmOne')->not->toContain('UvOne');
});

it('filters missing buy link (SCRAPED_UV with no source_links)', function (): void {
    Product::factory()->scrapedUv()->create(['name' => 'HasLink', 'source_links' => [['label' => 'S', 'url' => 'https://x.sg/1', 'kind' => 'local', 'price' => 1.0, 'currency' => 'SGD', 'last_checked' => null]]]);
    Product::factory()->scrapedUv()->create(['name' => 'NoLink', 'source_links' => []]);

    $res = $this->getJson('/api/admin/catalogue?missing_link=1')->assertOk();
    expect(collect($res->json('data'))->pluck('name'))->toContain('NoLink')->not->toContain('HasLink');
});

it('applies filters to the summary counts', function (): void {
    Product::factory()->scrapedUv()->create(['publish_state' => 'CANNOT_PUBLISH', 'print_method' => 'UV']);
    Product::factory()->model3d()->create(['publish_state' => 'CANNOT_PUBLISH', 'print_method' => 'FDM']);

    $res = $this->getJson('/api/admin/catalogue?print_method=UV')->assertOk();
    expect($res->json('counts.total'))->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/AdminCatalogueFiltersTest.php`
Expected: FAIL — filters not applied (wrong rows/counts), `source_kind` absent.

- [ ] **Step 3: Add a shared filter scope + apply it**

In `app/Http/Controllers/AdminCatalogueController.php::index`, after the `$searchScope` closure (line 58), add a reusable filter closure:

```php
        // Production filters, applied to BOTH the counts breakdown and the
        // paginator so the summary badges never contradict the visible rows.
        $filterScope = function ($q) use ($request): void {
            if ($request->filled('blocker')) {
                $q->whereJsonContains('cannot_publish_reasons', $request->string('blocker')->toString());
            }
            if ($request->filled('source')) {
                $q->where('source_kind', $request->string('source')->toString());
            }
            if ($request->filled('print_method')) {
                $q->where('print_method', $request->string('print_method')->toString());
            }
            if ($request->filled('category')) {
                $q->where('category', $request->string('category')->toString());
            }
            if ($request->boolean('ip_flagged')) {
                $q->where('ip_flagged', true);
            }
            if ($request->boolean('missing_link')) {
                // Blanks with no buy link to procure from (SCRAPED_UV only).
                $q->where('class', 'SCRAPED_UV')
                    ->where(fn ($w) => $w->whereNull('source_links')->orWhereRaw('JSON_LENGTH(source_links) = 0'));
            }
        };
```

Add `->where($filterScope)` to the `$byState` query (after `->where($searchScope)`, line 66):

```php
            ->where($searchScope)
            ->where($filterScope)
```

Add the same to the paginator query (after `->where($searchScope)`, line 90):

```php
            ->where($searchScope)
            ->where($filterScope)
```

Add `source_kind` to the row transform (in the `transform(fn ...)`, after `'source_url' => $p->source_url,` line 108):

```php
            'source_kind' => $p->source_kind,
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/AdminCatalogueFiltersTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AdminCatalogueController.php tests/Feature/AdminCatalogueFiltersTest.php
git commit -m "feat(catalogue): gate filters (blocker/source/print/category/ip/missing-link)" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Extract shared filter UI + refactor ProductAdminPage

**Files:**
- Create: `frontend/src/components/admin/Filters.tsx`, `frontend/src/components/admin/Filters.test.tsx`
- Modify: `frontend/src/pages/ProductAdminPage.tsx`

- [ ] **Step 1: Write the failing component test**

`frontend/src/components/admin/Filters.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { FilterChips } from './Filters';

describe('FilterChips', () => {
  const chips = [
    { key: 'source', label: 'Source: Local' },
    { key: 'blocker', label: 'Blocker: Missing dimensions' },
  ];

  it('renders a chip per active filter', () => {
    render(<FilterChips chips={chips} onRemove={() => {}} onClear={() => {}} />);
    expect(screen.getByText('Source: Local')).toBeInTheDocument();
    expect(screen.getByText('Blocker: Missing dimensions')).toBeInTheDocument();
  });

  it('calls onRemove with the chip key', async () => {
    const onRemove = vi.fn();
    render(<FilterChips chips={chips} onRemove={onRemove} onClear={() => {}} />);
    await userEvent.click(screen.getByLabelText('Remove filter: Source: Local'));
    expect(onRemove).toHaveBeenCalledWith('source');
  });

  it('renders nothing when there are no chips', () => {
    const { container } = render(<FilterChips chips={[]} onRemove={() => {}} onClear={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/components/admin/Filters.test.tsx`
Expected: FAIL — module not found.

- [ ] **Step 3: Create the shared component**

`frontend/src/components/admin/Filters.tsx`:

```tsx
import type { ReactNode } from 'react';
import { Button } from '../../ui';

export interface FilterChip {
  key: string;
  label: string;
}

/**
 * Numeric pill that reads as PART of its parent button (the "new chat badge"
 * look). Shared by both admin filter toolbars.
 */
export function CountPill({ children }: { children: ReactNode }) {
  return (
    <span className="ml-1.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-primary px-1.5 py-0.5 text-2xs font-semibold leading-none text-primary-fg">
      {children}
    </span>
  );
}

/**
 * Removable active-filter chips + a Clear all. Renders nothing when empty.
 * Pure presentational — the parent owns filter state (URL params).
 */
export function FilterChips({
  chips,
  onRemove,
  onClear,
}: {
  chips: FilterChip[];
  onRemove: (key: string) => void;
  onClear: () => void;
}) {
  if (chips.length === 0) return null;
  return (
    <div className="flex flex-wrap items-center gap-2">
      {chips.map((chip) => (
        <button
          key={chip.key}
          type="button"
          onClick={() => onRemove(chip.key)}
          className="inline-flex items-center gap-1.5 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-fg transition-colors hover:border-danger hover:text-danger"
          aria-label={`Remove filter: ${chip.label}`}
        >
          {chip.label}
          <span aria-hidden="true">✕</span>
        </button>
      ))}
      <Button variant="ghost" size="sm" onClick={onClear}>
        Clear all
      </Button>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/components/admin/Filters.test.tsx`
Expected: PASS.

- [ ] **Step 5: Refactor ProductAdminPage to consume the shared pieces**

In `frontend/src/pages/ProductAdminPage.tsx`:

1. Delete the local `CountPill` definition (lines 43-54).
2. Add import: `import { CountPill, FilterChips } from '../components/admin/Filters';`
3. Replace the active-chip block (lines 240-259) with:

```tsx
        <FilterChips
          chips={filterChips}
          onRemove={(key) => setParam(key, '')}
          onClear={clearAll}
        />
```

Leave `filterChips`, `setParam`, `clearAll`, and the `CountPill` usages on the Filters button + gate link unchanged — they now resolve to the imported `CountPill`.

- [ ] **Step 6: Verify ProductAdminPage still builds + no behaviour change**

Run: `cd frontend && npx vitest run && npm run build`
Expected: green (existing ProductAdminPage tests unchanged; build clean).

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/admin/Filters.tsx frontend/src/components/admin/Filters.test.tsx frontend/src/pages/ProductAdminPage.tsx
git commit -m "refactor(admin): extract shared CountPill + FilterChips" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Gate — URL-param filters + modal + new fields

**Files:**
- Create: `frontend/src/lib/sourceKind.ts`
- Modify: `frontend/src/types.ts`, `frontend/src/stores/catalogueAdminStore.ts:29-33`, `frontend/src/pages/CatalogueAdminPage.tsx`

- [ ] **Step 1: Add the source-kind label map + type**

`frontend/src/lib/sourceKind.ts`:

```ts
export type SourceKind = 'marketplace' | 'local' | 'makerworld' | 'thingiverse' | 'cults3d' | 'manual';

export const SOURCE_KIND_LABELS: Record<SourceKind, string> = {
  marketplace: 'Marketplace',
  local: 'Local supplier',
  makerworld: 'MakerWorld',
  thingiverse: 'Thingiverse',
  cults3d: 'Cults3D',
  manual: 'Manual',
};
```

- [ ] **Step 2: Extend the store filter type**

In `frontend/src/stores/catalogueAdminStore.ts`, replace the two filter type occurrences (lines 29 and 31-32) so both `lastFilter` and `fetch`'s `filter` use:

```ts
    filter?: {
      class?: string;
      state?: string;
      search?: string;
      page?: number;
      sort?: string;
      dir?: string;
      blocker?: string;
      source?: string;
      print_method?: string;
      category?: string;
      ip_flagged?: string;
      missing_link?: string;
    },
```

(Apply the same shape to `lastFilter?` on line 29.)

- [ ] **Step 3: Add `source_kind` to the item type**

In `frontend/src/types.ts`, in `AdminCatalogueItem` (after `source_url` line 400):

```ts
  source_kind: import('./lib/sourceKind').SourceKind | null;
```

- [ ] **Step 4: Migrate CatalogueAdminPage to URL params + modal**

In `frontend/src/pages/CatalogueAdminPage.tsx`:

1. Imports — add:

```tsx
import { useSearchParams } from 'react-router-dom';
import { Modal } from '../ui';
import { FilterIcon } from '../components/icons';
import { CountPill, FilterChips } from '../components/admin/Filters';
import { CATEGORIES, categoryLabel } from '../lib/categories';
import { SOURCE_KIND_LABELS, type SourceKind } from '../lib/sourceKind';
```

2. Replace the local filter `useState`s (lines 215-221: `classFilter, stateFilter, search, sort, dir, page`) with URL-param reads:

```tsx
  const [searchParams, setSearchParams] = useSearchParams();
  const classFilter = searchParams.get('class') ?? '';
  const stateFilter = searchParams.get('state') ?? '';
  const blocker = searchParams.get('blocker') ?? '';
  const source = searchParams.get('source') ?? '';
  const printMethod = searchParams.get('print_method') ?? '';
  const category = searchParams.get('category') ?? '';
  const ipFlagged = searchParams.get('ip_flagged') === '1';
  const missingLink = searchParams.get('missing_link') === '1';
  const sort = (searchParams.get('sort') as 'newest' | 'name' | 'base_cost') || 'newest';
  const dir: 'asc' | 'desc' = searchParams.get('dir') === 'asc' ? 'asc' : 'desc';
  const rawPage = Number(searchParams.get('page'));
  const page = Number.isInteger(rawPage) && rawPage > 0 ? rawPage : 1;
  const [filtersOpen, setFiltersOpen] = useState(false);

  const setParam = (key: string, value: string) => {
    setSearchParams(
      (prev) => {
        const p = new URLSearchParams(prev);
        if (value) p.set(key, value);
        else p.delete(key);
        if (key !== 'page') p.delete('page');
        return p;
      },
      { replace: true },
    );
  };
  const clearAll = () => setSearchParams({}, { replace: true });
```

3. Keep the debounced search but drive it off a URL-synced local input. Replace the search state block (lines 217, 226-230) with:

```tsx
  const q = searchParams.get('search') ?? '';
  const [qInput, setQInput] = useState(q);
  useEffect(() => setQInput(q), [q]);
  useEffect(() => {
    if (qInput === q) return;
    const t = setTimeout(() => setParam('search', qInput.trim()), 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qInput]);
```

4. Replace the fetch effect + `runFetch` (lines 232-258) so every filter param drives the fetch:

```tsx
  const fetchParams = {
    class: classFilter || undefined,
    state: stateFilter || undefined,
    search: q || undefined,
    blocker: blocker || undefined,
    source: source || undefined,
    print_method: printMethod || undefined,
    category: category || undefined,
    ip_flagged: ipFlagged ? '1' : undefined,
    missing_link: missingLink ? '1' : undefined,
    sort,
    dir,
    page,
  };
  const runFetch = () => void fetch(fetchParams);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { void fetch(fetchParams); }, [
    fetch, classFilter, stateFilter, q, blocker, source, printMethod, category,
    ipFlagged, missingLink, sort, dir, page,
  ]);
```

5. Replace the pagination `setPage` calls (lines 624, 632) with `setParam('page', String(...))`:

```tsx
              onClick={() => setParam('page', String(Math.max(1, page - 1)))}
```
```tsx
              onClick={() => setParam('page', String(page + 1))}
```

6. Build the chip list and replace the inline filter row (lines 384-440) with a Filters button + chips + modal:

```tsx
  const filterChips = useMemo(() => {
    const chips: { key: string; label: string }[] = [];
    if (q) chips.push({ key: 'search', label: `Search: “${q}”` });
    if (classFilter) chips.push({ key: 'class', label: `Class: ${CLASS_LABELS[classFilter as ProductClass]}` });
    if (stateFilter) chips.push({ key: 'state', label: `State: ${STATE_LABELS[stateFilter as PublishState]}` });
    if (blocker) chips.push({ key: 'blocker', label: `Blocker: ${blockerLabel(blocker)}` });
    if (source) chips.push({ key: 'source', label: `Source: ${SOURCE_KIND_LABELS[source as SourceKind] ?? source}` });
    if (printMethod) chips.push({ key: 'print_method', label: `Print: ${printMethod}` });
    if (category) chips.push({ key: 'category', label: `Category: ${categoryLabel(category)}` });
    if (ipFlagged) chips.push({ key: 'ip_flagged', label: 'IP-flagged' });
    if (missingLink) chips.push({ key: 'missing_link', label: 'Missing buy link' });
    return chips;
  }, [q, classFilter, stateFilter, blocker, source, printMethod, category, ipFlagged, missingLink]);
```

```tsx
        {/* Filters entry point + chips */}
        <div className="flex flex-col gap-3">
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div className="flex-1">
              <Input
                label="Search"
                type="search"
                placeholder="Search by product name or creator…"
                value={qInput}
                onChange={(e) => setQInput(e.target.value)}
              />
            </div>
            <Button variant="outline" onClick={() => setFiltersOpen(true)} className="sm:mb-0.5">
              <FilterIcon />
              Filters
              {filterChips.length > 0 && <CountPill>{filterChips.length}</CountPill>}
            </Button>
          </div>
          <FilterChips chips={filterChips} onRemove={(key) => setParam(key, '')} onClear={clearAll} />
        </div>

        <Modal
          open={filtersOpen}
          onClose={() => setFiltersOpen(false)}
          title="Filters"
          size="lg"
          footer={
            <>
              <Button variant="ghost" onClick={clearAll}>Clear all</Button>
              <Button variant="primary" onClick={() => setFiltersOpen(false)}>Done</Button>
            </>
          }
        >
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label="Class" value={classFilter} onChange={(e) => setParam('class', e.target.value)}>
              <option value="">All classes</option>
              <option value="SCRAPED_UV">UV Print</option>
              <option value="MODEL_3D">3D Printed</option>
            </Select>
            <Select label="State" value={stateFilter} onChange={(e) => setParam('state', e.target.value)}>
              <option value="">All states</option>
              <option value="PENDING">Pending</option>
              <option value="READY_TO_APPROVE">Ready to approve</option>
              <option value="PUBLISHED">Published</option>
              <option value="CANNOT_PUBLISH">Cannot publish</option>
            </Select>
            <Select label="Blocker" value={blocker} onChange={(e) => setParam('blocker', e.target.value)}>
              <option value="">Any blocker</option>
              {Object.entries(BLOCKER_LABELS).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </Select>
            <Select label="Source" value={source} onChange={(e) => setParam('source', e.target.value)}>
              <option value="">All sources</option>
              {(Object.keys(SOURCE_KIND_LABELS) as SourceKind[]).map((k) => (
                <option key={k} value={k}>{SOURCE_KIND_LABELS[k]}</option>
              ))}
            </Select>
            <Select label="Print method" value={printMethod} onChange={(e) => setParam('print_method', e.target.value)}>
              <option value="">All methods</option>
              <option value="UV">UV</option>
              <option value="FDM">FDM</option>
              <option value="RESIN">Resin</option>
            </Select>
            <Select label="Category" value={category} onChange={(e) => setParam('category', e.target.value)}>
              <option value="">All categories</option>
              {CATEGORIES.map((c) => (
                <option key={c.key} value={c.key}>{c.label}</option>
              ))}
            </Select>
            <Select label="Sort by" value={sort} onChange={(e) => setParam('sort', e.target.value)}>
              <option value="newest">Creation date</option>
              <option value="name">Name</option>
              <option value="base_cost">Base cost</option>
            </Select>
            <Select label="Direction" value={dir} onChange={(e) => setParam('dir', e.target.value)}>
              <option value="desc">Descending</option>
              <option value="asc">Ascending</option>
            </Select>
            <label className="inline-flex items-center gap-2 text-sm text-fg">
              <input type="checkbox" className="h-4 w-4 accent-[var(--color-primary)]" checked={ipFlagged} onChange={(e) => setParam('ip_flagged', e.target.checked ? '1' : '')} />
              IP-flagged only
            </label>
            <label className="inline-flex items-center gap-2 text-sm text-fg">
              <input type="checkbox" className="h-4 w-4 accent-[var(--color-primary)]" checked={missingLink} onChange={(e) => setParam('missing_link', e.target.checked ? '1' : '')} />
              Missing buy link
            </label>
          </div>
        </Modal>
```

> Remove the now-unused `page`/`setPage`/`classFilter`/`stateFilter`/`search`/`sort`/`dir` `useState` lines and the old `useEffect([...])` that reset page — the URL params replace them. Keep all row-rendering, counts, bulk-publish, and `Model3dRowTools` code unchanged.

- [ ] **Step 5: Verify build + tests**

Run: `cd frontend && npx vitest run && npm run build`
Expected: green (no TS errors; unused-var lint clean).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/sourceKind.ts frontend/src/types.ts frontend/src/stores/catalogueAdminStore.ts frontend/src/pages/CatalogueAdminPage.tsx
git commit -m "feat(catalogue): gate Filters modal + chips + URL-param state" -m "Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Full verification

- [ ] **Step 1: Backend filter + regression suite**

Run: `php artisan test --filter='SourceKind|AdminCatalogueFilters|CompletenessGate'`
Expected: PASS.

- [ ] **Step 2: Whole backend suite (regressions)**

Run: `php artisan test`
Expected: green.

- [ ] **Step 3: Frontend suite + build**

Run: `cd frontend && npx vitest run && npm run build`
Expected: green.

- [ ] **Step 4: Manual smoke (preview)**

Open the catalogue gate, open **Filters**, pick Source = Local and Blocker = Missing dimensions → URL gains `?source=local&blocker=missing_dimensions`, two chips appear, list + summary counts narrow together. Remove a chip → param + rows update. Reload → filters persist. Confirm ProductAdminPage filters still behave exactly as before.

---

## Self-review

**Spec coverage:**
- Filters modal + chips + clear-all — Tasks 3-4. ✓
- URL-param filter state — Task 4. ✓
- Shared component extraction + ProductAdminPage refactor — Task 3. ✓
- Filters: blocker, source, print_method, category, ip_flagged, missing_link — Task 2 (backend) + Task 4 (UI). ✓
- `source_kind` persisted column + derivation + backfill — Task 1. ✓
- Counts respect new filters — Task 2 (`$filterScope` on `$byState`). ✓
- Live-apply UX — Task 4 (each `setParam` writes URL + refetches; Done only closes). ✓
- Gate scope stays SCRAPED_UV + MODEL_3D — unchanged `whereIn` in the controller. ✓
- Dependency on Phase 1 `source_links` — stated in header + used in `missing_link` (Task 2) and factory seeds. ✓

**Placeholder scan:** every code step has full code; commands have expected output. The Task 4 note lists exact `useState` lines to remove (guidance, not a placeholder — replacement code is shown). ✓

**Type consistency:** `SourceKind::fromUrl` (Task 1) ↔ `source_kind` column ↔ `source` filter param (Task 2) ↔ frontend `SourceKind`/`SOURCE_KIND_LABELS` (Task 4). Store filter keys (Task 4 Step 2) match the controller params (Task 2 Step 3). `FilterChips`/`CountPill` signatures identical across Tasks 3-4. ✓
