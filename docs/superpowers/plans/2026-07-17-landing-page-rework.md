# Public landing page rework — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the public home page as a B2B marketplace shelf that duplicates nothing already in the header, and surface the two orphaned routes (`/kits`, `/gift-ideas`) in nav.

**Architecture:** `HomePage.tsx` keeps composition and catalogue pagination only; the rails become three focused components under `components/home/`. The reorder rail is optional and silent — it fetches its own data and renders null on empty or error, so a `/quotes` failure can never break the public shelf. No backend changes: every row is fed by an endpoint that already exists.

**Tech Stack:** React 18 + TypeScript, react-router-dom, zustand, Tailwind, Vitest + @testing-library/react, axios.

**Spec:** `docs/superpowers/specs/2026-07-17-landing-page-rework-design.md`

**Two corrections to the spec, made during planning:**
1. The spec said the browse grid loads "20 per page". Page size is server-controlled at 24/page (`frontend/src/lib/catalogue.ts:16`). "Load more" just increments `page`; we never set a size.
2. The spec said the reorder rail calls `fetchQuotes(1)` from `quoteStore`. That action returns `Promise<void>`, writes into shared store state, and swallows errors into a shared `error` field (`frontend/src/stores/quoteStore.ts:62-75`) — wrong shape for an optional silent row, and it would clobber `QuoteListPage`'s state. Task 4 adds a `fetchRecentQuotes` helper instead, mirroring the existing best-effort `fetchRelated` pattern (`frontend/src/lib/catalogue.ts:30-35`).

**Run all commands from `frontend/`.** Test runner: `npx vitest run <path>`. Full suite: `npm test`. Types: `npm run typecheck`.

---

## File structure

| File | Responsibility |
|---|---|
| Create `src/components/home/CategoryRail.tsx` | The 8 category links as one horizontal icon band. No props, no data. |
| Create `src/components/home/ProductRail.tsx` | Generic button-driven carousel over `Product[]`. Moved out of `HomePage.tsx`. |
| Create `src/components/home/PromoTiles.tsx` | Two static promo tiles (Build a kit / Bulk pricing). |
| Create `src/components/home/ReorderRail.tsx` | Logged-in-only past-quotes row. Owns its fetch, silent on empty/error. |
| Create `src/lib/quotes.ts` | `fetchRecentQuotes(limit)` — best-effort, returns `[]` on failure. |
| Modify `src/pages/HomePage.tsx` | Composition + catalogue fetch + Load more. Hero and Featured gifts deleted. |
| Modify `src/components/product/ProductCard.tsx:76-82` | Render MOQ beside the category badge. |
| Modify `src/components/SiteHeader.tsx:57-84, 86, 359-361` | Nav: add Kits + Gift ideas, drop Track order from desktop. Widen search to `md:`. |
| Modify `src/components/SiteFooter.tsx:11-33` | Add Track order to the Shop column. |

---

## Task 1: MOQ on product cards

**Files:**
- Modify: `src/components/product/ProductCard.tsx:76-82`
- Test: `src/components/product/ProductCard.test.tsx` (create)

`min_order_qty?: number` is already on `Product` (`src/types.ts:132`) and already on the wire. `showMeta` already gates the metadata row and every home caller passes it, so no new prop.

- [ ] **Step 1: Write the failing test**

Create `src/components/product/ProductCard.test.tsx`:

```tsx
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ProductCard } from './ProductCard';
import type { Product } from '../../types';

const product = (over: Partial<Product> = {}): Product =>
  ({
    id: 5,
    name: 'A5 Notebook',
    class: 'CORE',
    category: 'stationery',
    from_price: 7.58,
    currency: 'SGD',
    is_printable: true,
    availability: 'in_stock',
    ...over,
  }) as Product;

const renderCard = (p: Product, showMeta = true) =>
  render(
    <MemoryRouter>
      <ProductCard product={p} to="/products/5" showMeta={showMeta} />
    </MemoryRouter>,
  );

describe('ProductCard MOQ', () => {
  it('shows the minimum order quantity when above 1', () => {
    renderCard(product({ min_order_qty: 50 }));
    expect(screen.getByText(/min\. 50 units/i)).toBeInTheDocument();
  });

  it('hides MOQ when it is 1 - "Min. 1 units" is noise', () => {
    renderCard(product({ min_order_qty: 1 }));
    expect(screen.queryByText(/min\./i)).not.toBeInTheDocument();
  });

  it('hides MOQ when the field is absent', () => {
    renderCard(product());
    expect(screen.queryByText(/min\./i)).not.toBeInTheDocument();
  });

  it('hides MOQ when showMeta is false', () => {
    renderCard(product({ min_order_qty: 50 }), false);
    expect(screen.queryByText(/min\./i)).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/components/product/ProductCard.test.tsx`
Expected: FAIL — first test errors with `Unable to find an element with the text: /min\. 50 units/i`. The three negative tests pass already (nothing renders MOQ yet); that is correct and expected.

- [ ] **Step 3: Write minimal implementation**

In `src/components/product/ProductCard.tsx`, replace the category-badge block at lines 76-82:

```tsx
          {showMeta && product.category && (
            <div className="absolute left-2 top-2">
              <Badge tone="brand" size="sm">
                {categoryLabel(product.category)}
              </Badge>
            </div>
          )}
```

with:

```tsx
          {showMeta && (product.category || (product.min_order_qty ?? 0) > 1) && (
            <div className="absolute left-2 top-2 flex flex-col items-start gap-1">
              {product.category && (
                <Badge tone="brand" size="sm">
                  {categoryLabel(product.category)}
                </Badge>
              )}
              {(product.min_order_qty ?? 0) > 1 && (
                <Badge tone="neutral" size="sm">
                  Min. {product.min_order_qty} units
                </Badge>
              )}
            </div>
          )}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/components/product/ProductCard.test.tsx`
Expected: PASS, 4 tests.

If `tone="neutral"` is rejected by the `Badge` prop type, open `src/ui` and use whichever neutral/subtle tone the union actually offers — do not add a new tone.

- [ ] **Step 5: Commit**

```bash
git add src/components/product/ProductCard.tsx src/components/product/ProductCard.test.tsx
git commit -m "feat(product-card): show MOQ badge for bulk-only products"
```

---

## Task 2: CategoryRail

**Files:**
- Create: `src/components/home/CategoryRail.tsx`
- Test: `src/components/home/CategoryRail.test.tsx`

Replaces the "Shop by category" tile grid (`HomePage.tsx:102-125`) with a band sized as navigation furniture, not a headline section.

- [ ] **Step 1: Write the failing test**

Create `src/components/home/CategoryRail.test.tsx`:

```tsx
import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import CategoryRail from './CategoryRail';
import { CATEGORIES } from '../../lib/categories';

it('renders every category as a link to its filtered catalogue', () => {
  render(
    <MemoryRouter>
      <CategoryRail />
    </MemoryRouter>,
  );

  const links = screen.getAllByRole('link');
  expect(links).toHaveLength(CATEGORIES.length);
  CATEGORIES.forEach((c) => {
    expect(screen.getByRole('link', { name: new RegExp(c.label, 'i') })).toHaveAttribute(
      'href',
      `/products?category=${c.key}`,
    );
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/components/home/CategoryRail.test.tsx`
Expected: FAIL — `Failed to resolve import "./CategoryRail"`.

- [ ] **Step 3: Write minimal implementation**

Create `src/components/home/CategoryRail.tsx`:

```tsx
import { Link } from 'react-router-dom';
import { CATEGORIES } from '../../lib/categories';

/**
 * Category navigation band. Deliberately sized as furniture, not a headline
 * section - the header dropdown carries the same 8 links, so this must not
 * read as a second, competing "Shop by category" feature.
 */
export default function CategoryRail() {
  return (
    <nav aria-label="Shop by category">
      <ul className="grid grid-cols-4 gap-2 sm:grid-cols-8">
        {CATEGORIES.map((c) => (
          <li key={c.key}>
            <Link
              to={`/products?category=${c.key}`}
              className="flex min-h-[44px] flex-col items-center gap-1 rounded-lg border border-border bg-surface px-2 py-3 text-center transition-colors duration-fast hover:border-primary/50 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span className="text-2xl" aria-hidden="true">
                {c.icon}
              </span>
              <span className="text-xs font-medium text-fg">{c.label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/components/home/CategoryRail.test.tsx`
Expected: PASS, 1 test.

- [ ] **Step 5: Commit**

```bash
git add src/components/home/CategoryRail.tsx src/components/home/CategoryRail.test.tsx
git commit -m "feat(home): add category rail component"
```

---

## Task 3: Extract ProductRail

**Files:**
- Create: `src/components/home/ProductRail.tsx`
- Test: `src/components/home/ProductRail.test.tsx`

`NewArrivalsRail` + `RailButton` (`HomePage.tsx:220-287`) move out verbatim. Only change: the button `aria-label` is built from a `label` prop instead of hardcoding "arrivals", so the rail is reusable and reads correctly to screen readers.

- [ ] **Step 1: Write the failing test**

Create `src/components/home/ProductRail.test.tsx`:

```tsx
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ProductRail from './ProductRail';
import type { Product } from '../../types';

const items: Product[] = [1, 2, 3].map(
  (id) =>
    ({
      id,
      name: `Product ${id}`,
      class: 'CORE',
      category: 'stationery',
      from_price: 7.58,
      currency: 'SGD',
      is_printable: true,
      availability: 'in_stock',
    }) as Product,
);

const renderRail = () =>
  render(
    <MemoryRouter>
      <ProductRail items={items} label="new arrivals" />
    </MemoryRouter>,
  );

describe('ProductRail', () => {
  it('renders one card per item', () => {
    renderRail();
    items.forEach((p) => expect(screen.getByText(p.name)).toBeInTheDocument());
  });

  it('labels its buttons from the label prop', () => {
    renderRail();
    expect(screen.getByRole('button', { name: /previous new arrivals/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /next new arrivals/i })).toBeInTheDocument();
  });

  it('disables the previous button at the start', () => {
    renderRail();
    // jsdom reports 0 for every scroll metric, so the rail is simultaneously at
    // start and at end. Only the start edge is meaningfully assertable here.
    expect(screen.getByRole('button', { name: /previous new arrivals/i })).toBeDisabled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/components/home/ProductRail.test.tsx`
Expected: FAIL — `Failed to resolve import "./ProductRail"`.

- [ ] **Step 3: Write minimal implementation**

Create `src/components/home/ProductRail.tsx`:

```tsx
import { useCallback, useEffect, useRef, useState } from 'react';
import { ProductCard } from '../product/ProductCard';
import { productPath } from '../../lib/catalogue';
import type { Product } from '../../types';

/**
 * Button-driven carousel. No manual horizontal scroll - prev/next flank the
 * cards and disable at each edge. Programmatic scrollLeft still works under
 * overflow-x-hidden, so cards slide on click only.
 */
export default function ProductRail({ items, label }: { items: Product[]; label: string }) {
  const railRef = useRef<HTMLDivElement>(null);
  const [atStart, setAtStart] = useState(true);
  const [atEnd, setAtEnd] = useState(false);

  const updateEdges = useCallback(() => {
    const el = railRef.current;
    if (!el) return;
    setAtStart(el.scrollLeft <= 1);
    setAtEnd(el.scrollLeft + el.clientWidth >= el.scrollWidth - 1);
  }, []);

  useEffect(() => {
    updateEdges();
    window.addEventListener('resize', updateEdges);
    return () => window.removeEventListener('resize', updateEdges);
  }, [items, updateEdges]);

  const move = (dir: 1 | -1) => {
    const el = railRef.current;
    if (!el) return;
    el.scrollBy({ left: dir * Math.max(el.clientWidth * 0.8, 208), behavior: 'smooth' });
  };

  return (
    <div className="flex items-center gap-2 sm:gap-3">
      <RailButton dir="prev" label={label} onClick={() => move(-1)} disabled={atStart} />
      <div ref={railRef} onScroll={updateEdges} className="flex flex-1 gap-4 overflow-x-hidden py-1">
        {items.map((p) => (
          <div key={p.id} className="w-52 shrink-0">
            <ProductCard product={p} to={productPath(p)} showMeta />
          </div>
        ))}
      </div>
      <RailButton dir="next" label={label} onClick={() => move(1)} disabled={atEnd} />
    </div>
  );
}

function RailButton({
  dir,
  label,
  onClick,
  disabled,
}: {
  dir: 'prev' | 'next';
  label: string;
  onClick: () => void;
  disabled: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={`${dir === 'prev' ? 'Previous' : 'Next'} ${label}`}
      className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-border bg-surface text-fg-muted shadow-card transition-colors hover:bg-surface-2 hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-40"
    >
      <svg viewBox="0 0 20 20" className="h-5 w-5" fill="none" aria-hidden="true">
        <path
          d={dir === 'prev' ? 'M12 5l-5 5 5 5' : 'M8 5l5 5-5 5'}
          stroke="currentColor"
          strokeWidth="1.6"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
    </button>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/components/home/ProductRail.test.tsx`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/components/home/ProductRail.tsx src/components/home/ProductRail.test.tsx
git commit -m "refactor(home): extract ProductRail from HomePage"
```

---

## Task 4: fetchRecentQuotes helper

**Files:**
- Create: `src/lib/quotes.ts`
- Test: `src/lib/quotes.test.ts`

The reorder row must never surface an error or block the shelf below it, so it needs a fetch that resolves to `[]` rather than rejecting. This mirrors `fetchRelated` (`src/lib/catalogue.ts:30-35`), the codebase's existing best-effort-rail pattern. `quoteStore.fetchQuotes` is not usable here: it returns `void`, writes shared state consumed by `QuoteListPage`, and parks errors in a shared field.

- [ ] **Step 1: Write the failing test**

Create `src/lib/quotes.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest';
import api from './api';
import { fetchRecentQuotes } from './quotes';
import type { Quote } from '../types';

const quote = (id: number): Quote =>
  ({
    id,
    company_id: 1,
    state: 'ACCEPTED',
    currency: 'SGD',
    subtotal: '100.00',
    delivery: '0.00',
    total: '100.00',
    price_snapshot_at: null,
    notes: null,
    needed_by: null,
    created_at: '2026-07-01T00:00:00Z',
  }) as Quote;

afterEach(() => vi.restoreAllMocks());

describe('fetchRecentQuotes', () => {
  it('returns at most `limit` quotes', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { data: [quote(1), quote(2), quote(3), quote(4)], meta: { current_page: 1, last_page: 1, total: 4 } },
    } as any);

    await expect(fetchRecentQuotes(3)).resolves.toHaveLength(3);
  });

  it('resolves to an empty array when the request fails', async () => {
    vi.spyOn(api, 'get').mockRejectedValue(new Error('401'));

    await expect(fetchRecentQuotes(3)).resolves.toEqual([]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/lib/quotes.test.ts`
Expected: FAIL — `Failed to resolve import "./quotes"`.

- [ ] **Step 3: Write minimal implementation**

Create `src/lib/quotes.ts`:

```ts
import api from './api';
import type { Paginated, Quote } from '../types';

/**
 * Most recent quotes for the signed-in buyer, for the home reorder rail.
 * Best-effort: a failure (including a 401 on a stale session) yields an empty
 * list, never a rejection - the rail is optional and must not break the shelf.
 */
export function fetchRecentQuotes(limit: number): Promise<Quote[]> {
  return api
    .get<Paginated<Quote>>('/quotes', { params: { page: 1 } })
    .then((r) => r.data.data.slice(0, limit))
    .catch(() => []);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/lib/quotes.test.ts`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add src/lib/quotes.ts src/lib/quotes.test.ts
git commit -m "feat(quotes): add best-effort fetchRecentQuotes for home rail"
```

---

## Task 5: ReorderRail

**Files:**
- Create: `src/components/home/ReorderRail.tsx`
- Test: `src/components/home/ReorderRail.test.tsx`

Renders quote summaries, not products, and shows at most 3 — so no carousel, just a row. Renders null on empty or error: a buyer with no quotes must see exactly the logged-out shelf, with no apologetic row.

- [ ] **Step 1: Write the failing test**

Create `src/components/home/ReorderRail.test.tsx`:

```tsx
import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import ReorderRail from './ReorderRail';
import * as quotes from '../../lib/quotes';
import type { Quote } from '../../types';

const quote = (id: number): Quote =>
  ({
    id,
    company_id: 1,
    state: 'ACCEPTED',
    currency: 'SGD',
    subtotal: '100.00',
    delivery: '0.00',
    total: '250.00',
    price_snapshot_at: null,
    notes: null,
    needed_by: null,
    created_at: '2026-07-01T00:00:00Z',
  }) as Quote;

const renderRail = () =>
  render(
    <MemoryRouter>
      <ReorderRail />
    </MemoryRouter>,
  );

afterEach(() => vi.restoreAllMocks());

describe('ReorderRail', () => {
  it('links each quote to its detail page', async () => {
    vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([quote(7)]);
    renderRail();

    await waitFor(() =>
      expect(screen.getByRole('link', { name: /quote #7/i })).toHaveAttribute('href', '/quotes/7'),
    );
  });

  it('renders nothing when the buyer has no quotes', async () => {
    vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([]);
    const { container } = renderRail();

    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it('renders nothing when the fetch fails - never an error state', async () => {
    vi.spyOn(quotes, 'fetchRecentQuotes').mockRejectedValue(new Error('boom'));
    const { container } = renderRail();

    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it('asks for at most 3 quotes', async () => {
    const spy = vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([]);
    renderRail();

    await waitFor(() => expect(spy).toHaveBeenCalledWith(3));
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/components/home/ReorderRail.test.tsx`
Expected: FAIL — `Failed to resolve import "./ReorderRail"`.

- [ ] **Step 3: Write minimal implementation**

Create `src/components/home/ReorderRail.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { fetchRecentQuotes } from '../../lib/quotes';
import type { Quote } from '../../types';

const MAX_QUOTES = 3;

/**
 * Past-quote shortcuts for signed-in buyers - bulk B2B reordering is history-
 * driven, so a returning buyer's fastest path is their own last order.
 * Optional and silent: renders null on empty OR error. `fetchRecentQuotes`
 * already swallows failures; the catch here covers an unexpected throw so this
 * rail can never take the shelf down with it.
 */
export default function ReorderRail() {
  const [quotes, setQuotes] = useState<Quote[]>([]);

  useEffect(() => {
    let active = true;
    fetchRecentQuotes(MAX_QUOTES)
      .then((q) => {
        if (active) setQuotes(q);
      })
      .catch(() => {
        if (active) setQuotes([]);
      });
    return () => {
      active = false;
    };
  }, []);

  if (quotes.length === 0) return null;

  return (
    <section aria-labelledby="home-reorder">
      <div className="flex items-end justify-between gap-4">
        <h2 id="home-reorder" className="font-display text-xl text-fg sm:text-2xl">
          Reorder from a past quote
        </h2>
        <Link
          to="/quotes"
          className="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          View all
        </Link>
      </div>
      <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        {quotes.map((q) => (
          <li key={q.id}>
            <Link
              to={`/quotes/${q.id}`}
              aria-label={`Quote #${q.id}`}
              className="flex min-h-[44px] flex-col gap-1 rounded-xl border border-border bg-surface p-4 shadow-card transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span className="font-display text-sm text-fg">Quote #{q.id}</span>
              <span className="text-xs text-fg-muted">
                {q.currency} {q.total}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/components/home/ReorderRail.test.tsx`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/components/home/ReorderRail.tsx src/components/home/ReorderRail.test.tsx
git commit -m "feat(home): add reorder rail for signed-in buyers"
```

---

## Task 6: PromoTiles

**Files:**
- Create: `src/components/home/PromoTiles.tsx`
- Test: `src/components/home/PromoTiles.test.tsx`

Two static tiles. Hardcoded content, no CMS (per spec's out-of-scope list). "Build a kit" is the one that surfaces `/kits` on the page itself, on top of the nav link from Task 7.

- [ ] **Step 1: Write the failing test**

Create `src/components/home/PromoTiles.test.tsx`:

```tsx
import { expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import PromoTiles from './PromoTiles';

it('links to the kit builder and the catalogue', () => {
  render(
    <MemoryRouter>
      <PromoTiles />
    </MemoryRouter>,
  );

  expect(screen.getByRole('link', { name: /build a kit/i })).toHaveAttribute('href', '/kits');
  expect(screen.getByRole('link', { name: /bulk pricing/i })).toHaveAttribute('href', '/products');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/components/home/PromoTiles.test.tsx`
Expected: FAIL — `Failed to resolve import "./PromoTiles"`.

- [ ] **Step 3: Write minimal implementation**

Create `src/components/home/PromoTiles.tsx`:

```tsx
import { Link } from 'react-router-dom';

const TILES = [
  {
    to: '/kits',
    title: 'Build a kit',
    blurb: 'Bundle several gifts into one branded box for your team.',
    icon: '📦',
  },
  {
    to: '/products',
    title: 'Bulk pricing',
    blurb: 'Unit price drops as quantity climbs. Quote any item in the catalogue.',
    icon: '🏢',
  },
];

export default function PromoTiles() {
  return (
    <ul className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      {TILES.map((t) => (
        <li key={t.to}>
          <Link
            to={t.to}
            className="flex h-full items-start gap-3 rounded-2xl border border-border bg-gradient-to-br from-brand-50 via-surface to-accent-50 p-5 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            <span className="text-3xl" aria-hidden="true">
              {t.icon}
            </span>
            <span>
              <span className="block font-display text-base text-fg">{t.title}</span>
              <span className="block text-sm text-fg-muted">{t.blurb}</span>
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/components/home/PromoTiles.test.tsx`
Expected: PASS, 1 test.

- [ ] **Step 5: Commit**

```bash
git add src/components/home/PromoTiles.tsx src/components/home/PromoTiles.test.tsx
git commit -m "feat(home): add promo tiles surfacing kit builder"
```

---

## Task 7: Header and footer nav

**Files:**
- Modify: `src/components/SiteHeader.tsx:57-88`, `:359-361`
- Modify: `src/components/SiteFooter.tsx:12-18`
- Test: `src/components/SiteHeader.test.tsx` (modify), `src/components/SiteFooter.test.tsx` (modify)

Desktop nav becomes Products, Categories▾, Kits, Gift ideas — four items, same width as today. Track order leaves the desktop nav for the footer; it stays in the mobile drawer, where there is no footer competing and the drawer replaces the nav wholesale. Header search loses `hidden lg:block lg:w-56` and shows from `md:` up as the page's only search.

- [ ] **Step 1: Read the existing tests first**

Run: `cat src/components/SiteHeader.test.tsx src/components/SiteFooter.test.tsx`

These files already exist and assert current nav contents. Do not rewrite them wholesale — you are adding assertions and repairing any that assert Track order is in the desktop nav.

- [ ] **Step 2: Write the failing tests**

Append to `src/components/SiteHeader.test.tsx` (reuse whatever render helper the file already defines; if it renders `<SiteHeader />` inline inside `ThemeProvider` + `MemoryRouter`, match that exactly):

```tsx
it('links to kits and gift ideas from the desktop nav', () => {
  renderHeader();
  const nav = screen.getByRole('navigation', { name: /primary/i });
  expect(within(nav).getByRole('link', { name: /^kits$/i })).toHaveAttribute('href', '/kits');
  expect(within(nav).getByRole('link', { name: /gift ideas/i })).toHaveAttribute('href', '/gift-ideas');
});

it('drops track order from the desktop nav - it lives in the footer now', () => {
  renderHeader();
  const nav = screen.getByRole('navigation', { name: /primary/i });
  expect(within(nav).queryByRole('link', { name: /track order/i })).not.toBeInTheDocument();
});
```

Add `within` to the `@testing-library/react` import if it is not already there.

Append to `src/components/SiteFooter.test.tsx`:

```tsx
it('links to track order', () => {
  renderFooter();
  expect(screen.getByRole('link', { name: /track order/i })).toHaveAttribute('href', '/track');
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `npx vitest run src/components/SiteHeader.test.tsx src/components/SiteFooter.test.tsx`
Expected: FAIL — the kits/gift-ideas link and the footer track-order link are not found; the "drops track order" test fails because it is still in the nav.

- [ ] **Step 4: Write the implementation**

In `src/components/SiteHeader.tsx`, replace the Track order NavLink in the desktop nav (lines 62-64):

```tsx
          <NavLink to="/track" className={navLinkClass}>
            Track order
          </NavLink>
```

with:

```tsx
          <NavLink to="/kits" className={navLinkClass}>
            Kits
          </NavLink>
          <NavLink to="/gift-ideas" className={navLinkClass}>
            Gift ideas
          </NavLink>
```

Then widen the search form (line 86):

```tsx
        <form onSubmit={onSearch} role="search" className="hidden lg:block lg:w-56">
```

becomes:

```tsx
        <form onSubmit={onSearch} role="search" className="hidden md:block md:max-w-xs md:flex-1">
```

In the mobile drawer, add Kits and Gift ideas above the existing Track order link (line 359). Track order stays:

```tsx
            <NavLink to="/kits" onClick={onClose} className={navLinkClass}>
              Kits
            </NavLink>
            <NavLink to="/gift-ideas" onClick={onClose} className={navLinkClass}>
              Gift ideas
            </NavLink>
            <NavLink to="/track" onClick={onClose} className={navLinkClass}>
              Track order
            </NavLink>
```

In `src/components/SiteFooter.tsx`, extend the Shop column (lines 12-18):

```tsx
  {
    heading: 'Shop',
    links: [
      { label: 'Products', to: '/products' },
      { label: 'Kits', to: '/kits' },
      { label: 'Gift ideas', to: '/gift-ideas' },
      { label: 'Track order', to: '/track' },
      { label: 'Cart', to: '/cart' },
    ],
  },
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npx vitest run src/components/SiteHeader.test.tsx src/components/SiteFooter.test.tsx`
Expected: PASS. If a pre-existing header test asserted Track order in the nav, update that test — the removal is intended.

- [ ] **Step 6: Commit**

```bash
git add src/components/SiteHeader.tsx src/components/SiteFooter.tsx src/components/SiteHeader.test.tsx src/components/SiteFooter.test.tsx
git commit -m "feat(nav): surface kits and gift ideas, move track order to footer"
```

---

## Task 8: Recompose HomePage

**Files:**
- Modify: `src/pages/HomePage.tsx` (full rewrite)
- Test: `src/pages/HomePage.test.tsx` (full rewrite)

Hero (lines 58-99), category tile grid (102-125), Featured gifts (161-210), and the moved rail components (215-287) all go. What remains is composition, the catalogue fetch, and Load more.

The reorder rail renders for signed-in buyers only. `ReorderRail` returns null on an empty list anyway, but gating on `user` avoids an unauthenticated `/quotes` request on every anonymous pageview.

- [ ] **Step 1: Write the failing test**

Replace `src/pages/HomePage.test.tsx` entirely:

```tsx
import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import HomePage from './HomePage';
import * as catalogue from '../lib/catalogue';
import * as quotes from '../lib/quotes';
import { useAuthStore } from '../stores/authStore';
import type { Product, Quote } from '../types';

const product = (id: number): Product =>
  ({
    id,
    name: `Product ${id}`,
    class: 'CORE',
    category: 'stationery',
    from_price: 7.58,
    currency: 'SGD',
    is_printable: true,
    availability: 'in_stock',
  }) as Product;

const page = (ids: number[], current = 1, last = 1) => ({
  data: ids.map(product),
  meta: { current_page: current, last_page: last, total: ids.length },
});

const renderHome = () =>
  render(
    <ThemeProvider>
      <MemoryRouter>
        <HomePage />
      </MemoryRouter>
    </ThemeProvider>,
  );

afterEach(() => {
  vi.restoreAllMocks();
  useAuthStore.setState({ user: null });
});

describe('HomePage', () => {
  it('has no search - the header owns the only one', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.queryByRole('search')).not.toBeInTheDocument();
    expect(screen.queryByRole('searchbox')).not.toBeInTheDocument();
  });

  it('drops the Featured gifts section - there is no popularity signal', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.queryByText(/featured gifts/i)).not.toBeInTheDocument();
  });

  it('hides the reorder rail when signed out and never asks for quotes', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    const spy = vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([]);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(screen.queryByText(/reorder from a past quote/i)).not.toBeInTheDocument();
    expect(spy).not.toHaveBeenCalled();
  });

  it('shows the reorder rail when signed in with quotes', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([
      { id: 7, currency: 'SGD', total: '250.00' } as Quote,
    ]);
    useAuthStore.setState({ user: { id: 1, role: 'BUYER' } as any });
    renderHome();

    await waitFor(() => expect(screen.getByText(/reorder from a past quote/i)).toBeInTheDocument());
  });

  it('appends the next page on Load more, then hides the button at the last page', async () => {
    const fetchSpy = vi
      .spyOn(catalogue, 'fetchCatalogue')
      .mockImplementation((q: catalogue.CatalogueQuery = {}) =>
        Promise.resolve((q.sort === 'newest' ? page([9]) : page([1], q.page ?? 1, 2)) as any),
      );
    renderHome();

    const loadMore = await screen.findByRole('button', { name: /load more/i });
    fetchSpy.mockImplementation(() => Promise.resolve(page([2], 2, 2) as any));
    await userEvent.click(loadMore);

    await waitFor(() => expect(screen.getByText('Product 2')).toBeInTheDocument());
    expect(screen.getByText('Product 1')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /load more/i })).not.toBeInTheDocument();
  });

  it('surfaces a retry when the catalogue fails', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockRejectedValue(new Error('down'));
    renderHome();

    await waitFor(() => expect(screen.getByText(/could not load products/i)).toBeInTheDocument());
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/pages/HomePage.test.tsx`
Expected: FAIL — the search and Featured-gifts assertions fail against the current page, and no Load more button exists.

- [ ] **Step 3: Write the implementation**

Replace `src/pages/HomePage.tsx` entirely:

```tsx
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Button, EmptyState } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CardSkeleton, ProductCard } from '../components/product/ProductCard';
import CategoryRail from '../components/home/CategoryRail';
import PromoTiles from '../components/home/PromoTiles';
import ProductRail from '../components/home/ProductRail';
import ReorderRail from '../components/home/ReorderRail';
import { Motion, staggerContainer } from '../motion';
import { fetchCatalogue, productPath } from '../lib/catalogue';
import { useAuthStore } from '../stores/authStore';
import type { Product } from '../types';

const MAX_NEW = 8;

export default function HomePage() {
  const user = useAuthStore((s) => s.user);
  const [fresh, setFresh] = useState<Product[]>([]);
  const [browse, setBrowse] = useState<Product[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async (isActive: () => boolean = () => true) => {
    setLoading(true);
    setError(null);
    try {
      const [latest, first] = await Promise.all([
        fetchCatalogue({ sort: 'newest' }),
        fetchCatalogue({ page: 1 }),
      ]);
      if (!isActive()) return;
      setFresh(latest.data.slice(0, MAX_NEW));
      setBrowse(first.data);
      setPage(first.meta?.current_page ?? 1);
      setLastPage(first.meta?.last_page ?? 1);
    } catch {
      if (!isActive()) return;
      setError('We could not load products right now.');
    } finally {
      if (isActive()) setLoading(false);
    }
  };

  useEffect(() => {
    let active = true;
    void load(() => active);
    return () => {
      active = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Page size is server-controlled (24/page) - we only ever advance the page.
  const loadMore = async () => {
    setLoadingMore(true);
    try {
      const next = await fetchCatalogue({ page: page + 1 });
      setBrowse((prev) => [...prev, ...next.data]);
      setPage(next.meta?.current_page ?? page + 1);
      setLastPage(next.meta?.last_page ?? lastPage);
    } catch {
      // Keep what is already on screen: a failed append leaves the button in
      // place to retry rather than replacing a working grid with an error.
    } finally {
      setLoadingMore(false);
    }
  };

  return (
    <div className="flex flex-col gap-8 sm:gap-10">
      <CategoryRail />
      <PromoTiles />

      {user && <ReorderRail />}

      {/* A bare heading over a blank rail reads as broken, so the section is
          hidden outright when empty; the load error surfaces in Browse below. */}
      {(loading || fresh.length > 0) && (
        <section aria-labelledby="home-new">
          <div className="flex items-end justify-between gap-4">
            <h2 id="home-new" className="font-display text-xl text-fg sm:text-2xl">
              New arrivals
            </h2>
            <Link
              to="/products?sort=newest"
              className="inline-flex min-h-[44px] items-center text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              View all
            </Link>
          </div>
          <div className="mt-4">
            {loading ? (
              <div className="flex gap-4 overflow-hidden" aria-hidden="true">
                {Array.from({ length: 4 }).map((_, i) => (
                  <div key={i} className="w-52 shrink-0">
                    <CardSkeleton />
                  </div>
                ))}
              </div>
            ) : (
              <ProductRail items={fresh} label="new arrivals" />
            )}
          </div>
        </section>
      )}

      <section aria-labelledby="home-browse">
        <h2 id="home-browse" className="font-display text-xl text-fg sm:text-2xl">
          Browse the catalogue
        </h2>
        <div className="mt-4">
          {loading ? (
            <>
              <span className="sr-only" role="status" aria-live="polite">
                Loading products…
              </span>
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5" aria-hidden="true">
                {Array.from({ length: 5 }).map((_, i) => (
                  <CardSkeleton key={i} />
                ))}
              </div>
            </>
          ) : error ? (
            <ErrorState message={error} onRetry={() => void load()} />
          ) : browse.length === 0 ? (
            <EmptyState
              title="No products published yet"
              description="Our makers are hard at work. Check back soon for new customisable gifts."
              action={
                <Button variant="outline" onClick={() => void load()}>
                  Refresh
                </Button>
              }
            />
          ) : (
            <>
              <Motion
                variants={staggerContainer}
                initial="hidden"
                animate="visible"
                className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
              >
                {browse.map((p) => (
                  <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
                ))}
              </Motion>
              {page < lastPage && (
                <div className="mt-6 flex justify-center">
                  <Button variant="outline" onClick={() => void loadMore()} disabled={loadingMore}>
                    {loadingMore ? 'Loading…' : 'Load more'}
                  </Button>
                </div>
              )}
            </>
          )}
        </div>
      </section>
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/pages/HomePage.test.tsx`
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add src/pages/HomePage.tsx src/pages/HomePage.test.tsx
git commit -m "feat(home): rebuild as B2B marketplace shelf, drop hero and featured"
```

---

## Task 9: Full verification

**Files:** none — this task only runs things.

- [ ] **Step 1: Typecheck**

Run: `npm run typecheck`
Expected: exit 0, no output. `HomePage.tsx` no longer imports `Input`, `fadeInUp`, `useNavigate`, or `useRef`/`useCallback` — remove any that linger.

- [ ] **Step 2: Full test suite**

Run: `npm test`
Expected: all pass. `CataloguePage.test.tsx` and `ProductDetailPage.test.tsx` touch `ProductCard`; if either asserts an exact badge count on a card, the new MOQ badge may break it. Fix the assertion — the badge is intended.

- [ ] **Step 3: Drive the real page**

Use the `verify` skill, or start the dev server and check by hand:
- `/` signed out: category band, promo tiles, New arrivals, Browse + Load more. No search anywhere on the page body. No "Featured gifts".
- `/` signed in as a buyer with quotes: reorder row above New arrivals.
- Header at ≥768px: search box visible; nav reads Products, Categories▾, Kits, Gift ideas; no Track order.
- Footer: Track order link present and resolves.
- `/kits` and `/gift-ideas` reachable from the nav.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix(home): resolve verification findings"
```

---

## Self-review notes

Spec coverage checked task-by-task: rows (2, 3, 5, 6, 8), header (7), footer (7), components (2, 3, 5), card MOQ (1), error/loading states (5, 8), testing (every task). Two spec deviations are recorded at the top of this plan with reasons.
