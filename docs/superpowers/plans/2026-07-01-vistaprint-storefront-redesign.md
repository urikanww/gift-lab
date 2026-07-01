# Vistaprint-Angled Storefront Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Gift-Lab frontend into a professional, Vistaprint-grade custom-gifting storefront ("Bold Studio" dark/light theme, new Home + Product Detail pages, mega-nav, checkout framing), reusing the existing B2B quote/pay backend unchanged.

**Architecture:** Frontend-only. Retheme the existing CSS-var token layer to "Bold Studio", add ecommerce IA (Home, PDP, `/products`, `/design`, `/checkout` + redirects), rebuild global chrome, and reframe cart→checkout as commerce over the existing `POST /quotes` flow. Every existing API call, data contract, and Zustand store shape is preserved. Every page must be mobile-responsive (360→1280, no horizontal scroll).

**Tech Stack:** React 18 + TypeScript + Vite, react-router v6, Zustand, axios, Tailwind CSS, Framer Motion, fabric.js v6. Existing design system: tokens in `src/index.css` + `tailwind.config.js`, primitives in `src/ui/`, motion in `src/motion/`, `ThemeProvider` (light/dark), Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-07-01-vistaprint-storefront-redesign-design.md`

**Conventions for every task:** relative imports (no path alias), `npm` commands run from `frontend/`. "Verify green" = `npm run typecheck` clean AND `npm test` passing. Commit after each task.

---

## File Structure

**Create:**
- `frontend/src/pages/HomePage.tsx` — merchandised landing.
- `frontend/src/pages/HomePage.test.tsx`
- `frontend/src/pages/ProductDetailPage.tsx` — PDP (sticky gallery, variants, tier pricing).
- `frontend/src/pages/ProductDetailPage.test.tsx`
- `frontend/src/pages/CheckoutPage.tsx` — commerce framing over `POST /quotes`.
- `frontend/src/pages/CheckoutPage.test.tsx`
- `frontend/src/components/SiteHeader.tsx` — mega-nav (extract/replace nav from `Layout.tsx`).
- `frontend/src/components/SiteFooter.tsx` — footer with trust badges.
- `frontend/src/lib/catalogue.ts` — shared catalogue fetch helpers (`fetchCatalogue`, `fetchProduct`, `fetchTierPrices`) so Home/PDP/Catalogue don't duplicate axios calls.
- `frontend/src/lib/catalogue.test.ts`
- `frontend/src/lib/categories.ts` — `ProductClass`→label/icon map used by nav, Home, catalogue filters.

**Modify:**
- `frontend/src/index.css` — retheme token values to Bold Studio (dark + light).
- `frontend/src/ui/ThemeProvider.tsx` — default to dark when no stored preference.
- `frontend/src/App.tsx` — new routes + redirects.
- `frontend/src/components/Layout.tsx` — use `SiteHeader` + `SiteFooter`.
- `frontend/src/pages/CataloguePage.tsx` — move to `/products`; cards link to PDP; use `catalogue.ts`.
- `frontend/src/pages/CataloguePage.test.tsx` — update for new route/links.
- `frontend/src/pages/CartPage.tsx` — checkout CTA points to `/checkout`.
- `frontend/src/pages/ProductDesignerPage.tsx` — served at `/design/:id`; "add to cart" nav target unchanged logic.

---

## Phase 1 — Bold Studio theme

### Task 1: Retheme tokens to Bold Studio (dark + light)

**Files:**
- Modify: `frontend/src/index.css`
- Modify: `frontend/src/ui/ThemeProvider.tsx`

- [ ] **Step 1: Read current token definitions**

Run: open `frontend/src/index.css`. Locate the `:root` / `[data-theme="dark"]` (or equivalent) blocks that define `--bg`, `--surface`, `--surface-2`, `--border`, `--fg`, `--fg-muted`, `--primary`, `--primary-hover`, `--primary-fg`, `--accent-*`, `--ring`, shadows. Note their exact names (Agent 1 mapped these into `tailwind.config.js`; do NOT rename tokens — only change values).

- [ ] **Step 2: Set Bold Studio light values (`:root` / default) and dark values (`[data-theme="dark"]`)**

Update the values (keep existing token NAMES so Tailwind utilities keep working):

```css
/* Light (Bold Studio) */
:root, [data-theme="light"] {
  --bg: #f6f6fb;
  --surface: #ffffff;
  --surface-2: #f0f0f6;
  --border: #e6e6ef;
  --fg: #14141a;
  --fg-muted: #5b5b6b;
  --fg-subtle: #8a8a99;
  --primary: #ff3b5f;         /* coral accent */
  --primary-hover: #e82d50;
  --primary-fg: #ffffff;
  --accent: #6a4bff;          /* violet secondary (map to existing accent token name) */
  --ring: #ff3b5f;
}

/* Dark (Bold Studio) — default */
[data-theme="dark"] {
  --bg: #0e0e12;
  --surface: #16161d;
  --surface-2: #1e1e28;
  --border: #2a2a34;
  --fg: #f4f4f7;
  --fg-muted: #9a9aa8;
  --fg-subtle: #6f6f80;
  --primary: #ff4d6d;
  --primary-hover: #ff6382;
  --primary-fg: #ffffff;
  --accent: #7c5cff;
  --ring: #ff4d6d;
}
```

If a token name in the file differs (e.g. `--color-brand-500`), map these values onto the existing names rather than adding new ones. Keep the brand/accent numeric scales if present, but ensure the semantic tokens above resolve to Bold Studio colors. Also define a reusable gradient var if the system has one, else add:
```css
:root { --grad-brand: linear-gradient(135deg,#ff4d6d,#ff9e64); }
```

- [ ] **Step 3: Default theme to dark**

In `frontend/src/ui/ThemeProvider.tsx`, change `getInitialTheme()` final fallback from `prefers-color-scheme` to dark:

```ts
function getInitialTheme(): Theme {
  if (typeof window === 'undefined') return 'dark';
  const stored = window.localStorage.getItem(STORAGE_KEY);
  if (stored === 'light' || stored === 'dark') return stored;
  return 'dark';
}
```

- [ ] **Step 4: Verify green + visual smoke**

Run: `npm run typecheck && npm test`
Expected: typecheck clean, 25 tests pass.
Then `npm run build` — expected: succeeds.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/index.css frontend/src/ui/ThemeProvider.tsx
git commit -m "feat(ui): retheme design tokens to Bold Studio (dark default)"
```

---

## Phase 2 — Shared data + category helpers

### Task 2: Catalogue data helpers

**Files:**
- Create: `frontend/src/lib/catalogue.ts`
- Create: `frontend/src/lib/catalogue.test.ts`
- Create: `frontend/src/lib/categories.ts`

- [ ] **Step 1: Write failing test for helpers**

`frontend/src/lib/catalogue.test.ts`:
```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { fetchCatalogue, fetchProduct, fetchTierPrices } from './catalogue';

vi.mock('./api', () => ({ default: { get: vi.fn(), post: vi.fn() } }));

describe('catalogue lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('fetchCatalogue passes page param', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1 } });
    await fetchCatalogue(2);
    expect(api.get).toHaveBeenCalledWith('/catalogue', { params: { page: 2 } });
  });

  it('fetchProduct hits the show route', async () => {
    (api.get as any).mockResolvedValue({ data: { id: 5, name: 'A5' } });
    const p = await fetchProduct(5);
    expect(api.get).toHaveBeenCalledWith('/catalogue/5');
    expect(p.id).toBe(5);
  });

  it('fetchTierPrices posts one estimate per quantity and returns per-unit', async () => {
    (api.post as any).mockImplementation((_url: string, body: any) =>
      Promise.resolve({ data: { currency: 'SGD', lines: [{ unit_price: 6.4, line_total: 6.4 * body.line_items[0].qty }], subtotal: 0, delivery: 0, total: 0 } }),
    );
    const tiers = await fetchTierPrices(5, null, [25, 100]);
    expect(api.post).toHaveBeenCalledTimes(2);
    expect(tiers).toEqual([
      { qty: 25, unitPrice: 6.4, currency: 'SGD' },
      { qty: 100, unitPrice: 6.4, currency: 'SGD' },
    ]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm test -- catalogue.test`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement `catalogue.ts`**

```ts
import api from './api';
import type { Paginated, Product, PriceEstimate } from '../types';

export function fetchCatalogue(page = 1): Promise<Paginated<Product>> {
  return api.get<Paginated<Product>>('/catalogue', { params: { page } }).then((r) => r.data);
}

export function fetchProduct(id: number | string): Promise<Product> {
  return api.get<Product>(`/catalogue/${id}`).then((r) => r.data);
}

export interface TierPrice { qty: number; unitPrice: number; currency: string; }

export async function fetchTierPrices(
  productId: number,
  variantId: number | null,
  quantities: number[],
): Promise<TierPrice[]> {
  const results = await Promise.all(
    quantities.map((qty) =>
      api
        .post<PriceEstimate>('/price-estimate', {
          line_items: [{ product_id: productId, variant_id: variantId, qty, has_customization: false }],
        })
        .then((r) => ({ qty, unitPrice: r.data.lines[0]?.unit_price ?? 0, currency: r.data.currency })),
    ),
  );
  return results;
}
```
(If `Paginated<T>` is not exported from `types.ts`, add it there to match the shape used in `CataloguePage.tsx`: `{ data: T[]; current_page: number; last_page: number; }`.)

- [ ] **Step 4: Implement `categories.ts`**

```ts
import type { ProductClass } from '../types';

export interface Category { key: ProductClass; label: string; icon: string; }

export const CATEGORIES: Category[] = [
  { key: 'CORE', label: 'Core gifts', icon: '📓' },
  { key: 'SCRAPED_UV', label: 'UV print', icon: '☕' },
  { key: 'MODEL_3D', label: '3D prints', icon: '🧩' },
];

export function categoryLabel(c: ProductClass): string {
  return CATEGORIES.find((x) => x.key === c)?.label ?? c;
}
```

- [ ] **Step 5: Verify green + commit**

Run: `npm test -- catalogue.test` (PASS), then `npm run typecheck`.
```bash
git add frontend/src/lib/catalogue.ts frontend/src/lib/catalogue.test.ts frontend/src/lib/categories.ts frontend/src/types.ts
git commit -m "feat(catalogue): shared fetch + category helpers"
```

---

## Phase 3 — Global chrome

### Task 3: SiteHeader (mega-nav, responsive)

**Files:**
- Create: `frontend/src/components/SiteHeader.tsx`
- Modify: `frontend/src/components/Layout.tsx`
- Test: `frontend/src/components/SiteHeader.test.tsx`

- [ ] **Step 1: Failing test**

```tsx
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import SiteHeader from './SiteHeader';

function renderHeader() {
  return render(
    <ThemeProvider><MemoryRouter><SiteHeader /></MemoryRouter></ThemeProvider>,
  );
}

it('renders brand, primary nav, and a theme toggle', () => {
  renderHeader();
  expect(screen.getByRole('link', { name: /giftlab/i })).toBeInTheDocument();
  expect(screen.getByRole('link', { name: /products/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /theme/i })).toBeInTheDocument();
});
```

- [ ] **Step 2: Run to verify fail**

Run: `npm test -- SiteHeader.test` → FAIL (module not found).

- [ ] **Step 3: Implement SiteHeader**

Build a sticky, backdrop-blur header using tokens + primitives. Requirements:
- Brand link `GIFT<span class="text-primary">LAB</span>` → `/`.
- Desktop nav links: Products (`/products`), and category quick-links from `CATEGORIES`. Cart link with count badge from `useCartStore` (`lines.length`). Account: `/quotes` ("My Orders") when logged in (`useAuthStore`), else `/login`.
- Search input (client-side; navigates to `/products?q=`).
- Theme toggle button (`useTheme().toggleTheme`), `aria-label="Toggle theme"` (matches test `/theme/i`), shows ☀/☾.
- Mobile (`< md`): collapse links into a hamburger button that opens a drawer (reuse `Modal` or a simple slide-over with Framer Motion + `AnimatePresence`); keep cart + theme toggle visible. No horizontal scroll.
- Use `NavLink` for active styling.

Show the full component code following the existing `Layout.tsx` nav patterns (import from `../ui`, `../motion`, `../stores/*`, `../lib/categories`). Keep it under ~150 lines; extract the drawer into an inline subcomponent if needed.

- [ ] **Step 4: Wire into Layout**

In `Layout.tsx`, replace the existing inline `<nav>`/header markup with `<SiteHeader />` and add `<SiteFooter />` after `<Outlet />` (footer created in Task 4). Keep the skip-link and `<AnimatedOutlet />`/page-transition wiring intact.

- [ ] **Step 5: Verify green + commit**

Run: `npm test -- SiteHeader.test` (PASS), `npm run typecheck`, `npm test` (all pass).
```bash
git add frontend/src/components/SiteHeader.tsx frontend/src/components/SiteHeader.test.tsx frontend/src/components/Layout.tsx
git commit -m "feat(ui): Bold Studio mega-nav header with responsive drawer"
```

### Task 4: SiteFooter

**Files:**
- Create: `frontend/src/components/SiteFooter.tsx`
- Test: `frontend/src/components/SiteFooter.test.tsx`

- [ ] **Step 1: Failing test**

```tsx
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import SiteFooter from './SiteFooter';

it('renders trust badges and link columns', () => {
  render(<MemoryRouter><SiteFooter /></MemoryRouter>);
  expect(screen.getByText(/secure checkout/i)).toBeInTheDocument();
  expect(screen.getByRole('contentinfo')).toBeInTheDocument();
});
```

- [ ] **Step 2: Verify fail** — `npm test -- SiteFooter.test` → FAIL.

- [ ] **Step 3: Implement** a `<footer>` (role contentinfo) with a trust row (⚡ 3-day turnaround, 🎨 live 2D+3D preview, 🔒 secure checkout, 🏢 bulk & corporate), 2-3 link columns (Products, Company, Help), and copyright. Responsive: columns stack on mobile (`grid` → 1 col). Tokens only.

- [ ] **Step 4: Verify green + commit**
```bash
git add frontend/src/components/SiteFooter.tsx frontend/src/components/SiteFooter.test.tsx
git commit -m "feat(ui): storefront footer with trust badges"
```

---

## Phase 4 — Routing + IA

### Task 5: New routes and redirects

**Files:**
- Modify: `frontend/src/App.tsx`

- [ ] **Step 1: Add routes + redirects**

Update `<Routes>` inside `Layout`:
```tsx
<Route index element={<HomePage />} />
<Route path="products" element={<CataloguePage />} />
<Route path="products/:id" element={<ProductDetailPage />} />
<Route path="design/:id" element={<ProductDesignerPage />} />
<Route path="cart" element={<CartPage />} />
<Route path="checkout" element={<CheckoutPage />} />
<Route path="login" element={<LoginPage />} />
{/* protected: quotes, quotes/:id, production-queue, procurement, catalogue-admin — unchanged */}
{/* legacy redirects */}
<Route path="catalogue" element={<Navigate to="/products" replace />} />
<Route path="catalogue/:id" element={<RedirectCatalogueToProduct />} />
<Route path="*" element={<Navigate to="/" replace />} />
```
Add imports for `HomePage`, `ProductDetailPage`, `CheckoutPage` (created in later tasks — this task may temporarily import not-yet-created files; create thin placeholders first so typecheck passes, OR sequence Task 5 after Tasks 6-8. Recommended: do Task 5 LAST of Phase 4-6; here we add the redirect helper now and the page routes as their pages land).

Add a small redirect helper (preserves the id):
```tsx
import { useParams } from 'react-router-dom';
function RedirectCatalogueToProduct() {
  const { id } = useParams();
  return <Navigate to={`/products/${id}`} replace />;
}
```

- [ ] **Step 2: Verify green + commit** (after dependent pages exist).
```bash
git add frontend/src/App.tsx
git commit -m "feat(routing): storefront IA routes + legacy redirects"
```

> **Sequencing note:** Create the pages (Tasks 6-8) before flipping `index` to `HomePage` to keep the app runnable. Until then, keep `index` on `CataloguePage`.

---

## Phase 5 — Home

### Task 6: HomePage

**Files:**
- Create: `frontend/src/pages/HomePage.tsx`
- Test: `frontend/src/pages/HomePage.test.tsx`

- [ ] **Step 1: Failing test**

```tsx
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { vi } from 'vitest';
import { ThemeProvider } from '../ui';
import HomePage from './HomePage';
import * as catalogue from '../lib/catalogue';

vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue({
  data: [{ id: 5, name: 'A5 Notebook', class: 'CORE', from_price: 7.58, currency: 'SGD', is_printable: true } as any],
  current_page: 1, last_page: 1,
});

it('renders hero, categories, and popular products', async () => {
  render(<ThemeProvider><MemoryRouter><HomePage /></MemoryRouter></ThemeProvider>);
  expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  expect(screen.getByText(/shop by category/i)).toBeInTheDocument();
  await waitFor(() => expect(screen.getByText(/A5 Notebook/)).toBeInTheDocument());
});
```

- [ ] **Step 2: Verify fail** — `npm test -- HomePage.test` → FAIL.

- [ ] **Step 3: Implement HomePage** with sections from spec §6.1: hero (h1, eyebrow, dual CTA → `/design` & `/products`, floating preview chips), shop-by-category grid (from `CATEGORIES`, each links `/products?class=KEY`), popular products grid (first N of `fetchCatalogue(1)`, cards link `/products/:id`), how-it-works (3 steps), trust bar. Use `Motion`/`staggerContainer`/`staggerItem`, `AsyncBoundary` (from `../components/ui/States`) or explicit loading/empty/error via `Skeleton`/`EmptyState`. Responsive: grids reflow (category 3→2, products 4→2→1), hero stacks on mobile.

- [ ] **Step 4: Verify green** — `npm test -- HomePage.test` (PASS), `npm run typecheck`.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/pages/HomePage.tsx frontend/src/pages/HomePage.test.tsx
git commit -m "feat(home): merchandised storefront homepage"
```

---

## Phase 6 — Product Detail Page

### Task 7: ProductDetailPage (sticky gallery, variants, tier pricing)

**Files:**
- Create: `frontend/src/pages/ProductDetailPage.tsx`
- Test: `frontend/src/pages/ProductDetailPage.test.tsx`

- [ ] **Step 1: Failing test**

```tsx
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { vi } from 'vitest';
import { ThemeProvider } from '../ui';
import ProductDetailPage from './ProductDetailPage';
import * as catalogue from '../lib/catalogue';

vi.spyOn(catalogue, 'fetchProduct').mockResolvedValue({
  id: 5, name: 'A5 Hardcover Notebook', description: 'Blank core', class: 'CORE',
  from_price: 7.58, currency: 'SGD', dimensions: { l: 148, w: 15, h: 210, unit: 'mm' },
  weight: '300', print_method: 'UV', stock_mode: 'STOCKED', image_url: null,
  is_printable: true, creator_credit: null, variants: [],
} as any);
vi.spyOn(catalogue, 'fetchTierPrices').mockResolvedValue([{ qty: 25, unitPrice: 7.58, currency: 'SGD' }]);

it('renders product name, price, and a Customize CTA linking to the designer', async () => {
  render(
    <ThemeProvider><MemoryRouter initialEntries={['/products/5']}>
      <Routes><Route path="/products/:id" element={<ProductDetailPage />} /></Routes>
    </MemoryRouter></ThemeProvider>,
  );
  await waitFor(() => expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument());
  const cta = screen.getByRole('link', { name: /customize/i });
  expect(cta).toHaveAttribute('href', '/design/5');
});
```

- [ ] **Step 2: Verify fail** — `npm test -- ProductDetailPage.test` → FAIL.

- [ ] **Step 3: Implement PDP** per spec §6.3:
  - Load product via `fetchProduct(id)`; loading→`Skeleton`, error→retry, not-found→`EmptyState`.
  - Two-column grid (`md:grid-cols-2`, `items-start`). Left `.gallery` uses `md:sticky md:top-20 self-start` (sticky ONLY at `md+`; normal flow on mobile). Main image + thumbnail strip.
  - Right: breadcrumb (`Products / {category} / {name}`), `<h1>`, rating summary (static placeholder stars + count — reviews are presentational), price, description, color swatches (from `product.variants` attributes if present, else base), print-method options, **quantity tier pricing** via `fetchTierPrices(id, variantId, [25,100,250,500])` rendered as selectable tiles, primary CTA `<Link to={`/design/${id}`}>Customize in studio</Link>`, secondary "Add sample to cart" (calls `useCartStore.addLine(product, selectedVariant, {})` then toast), trust mini-row.
  - Below full-width: Specifications (from `product.dimensions`/`weight`/`print_method`/`stock_mode`), reviews (presentational), related products (`fetchCatalogue` first few, excluding current id).
  - Responsive: columns stack, tiers wrap, CTAs full-width, optional sticky bottom "Customize" bar on mobile.

- [ ] **Step 4: Verify green** — `npm test -- ProductDetailPage.test` (PASS), `npm run typecheck`.

- [ ] **Step 5: Commit**
```bash
git add frontend/src/pages/ProductDetailPage.tsx frontend/src/pages/ProductDetailPage.test.tsx
git commit -m "feat(pdp): product detail page with sticky gallery and tier pricing"
```

---

## Phase 7 — Catalogue evolve

### Task 8: Catalogue at /products, cards link to PDP

**Files:**
- Modify: `frontend/src/pages/CataloguePage.tsx`
- Modify: `frontend/src/pages/CataloguePage.test.tsx`

- [ ] **Step 1: Update product-card link target**

Change product cards so clicking navigates to `/products/:id` (PDP), not `/catalogue/:id` (designer). Read the `?q=` and `?class=` query params (via `useSearchParams`) to pre-filter/search client-side (search already client-side today). Replace inline `api.get('/catalogue', …)` with `fetchCatalogue(page)` from `../lib/catalogue`.

- [ ] **Step 2: Update test**

In `CataloguePage.test.tsx`, update the assertion that a product links somewhere so it expects `/products/<id>` (was `/catalogue/<id>`). Keep the existing "renders published products from the API" behavior (mock `fetchCatalogue` or `api.get` as the test currently does).

- [ ] **Step 3: Verify green** — `npm test -- CataloguePage.test` (PASS), `npm run typecheck`.

- [ ] **Step 4: Commit**
```bash
git add frontend/src/pages/CataloguePage.tsx frontend/src/pages/CataloguePage.test.tsx
git commit -m "feat(catalogue): move to /products; cards open the PDP"
```

---

## Phase 8 — Cart → Checkout

### Task 9: CheckoutPage with login gate + quote creation

**Files:**
- Create: `frontend/src/pages/CheckoutPage.tsx`
- Test: `frontend/src/pages/CheckoutPage.test.tsx`
- Modify: `frontend/src/pages/CartPage.tsx`

- [ ] **Step 1: Confirm the existing quote-creation call**

Find how the current cart/quote request creates a quote (search for `POST`/`/quotes` and `createQuote` in `frontend/src/stores/quoteStore.ts` and `CartPage.tsx`). Reuse that exact store action — do NOT invent a new endpoint. Note its name/signature for the code below (referred to here as `useQuoteStore().createQuote(...)`; use the real name found).

- [ ] **Step 2: Failing test (login gate)**

```tsx
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { vi } from 'vitest';
import { ThemeProvider } from '../ui';
import CheckoutPage from './CheckoutPage';
import { useCartStore } from '../stores/cartStore';
import { useAuthStore } from '../stores/authStore';

it('prompts anonymous users to log in before placing the order', () => {
  useCartStore.setState({ lines: [{ key: 'k', product: { id: 5, name: 'A5' } as any, variant: null, qty: 1, customization: {} }] });
  useAuthStore.setState({ user: null, status: 'ready' } as any);
  render(
    <ThemeProvider><MemoryRouter initialEntries={['/checkout']}>
      <Routes><Route path="/checkout" element={<CheckoutPage />} /><Route path="/login" element={<div>Login screen</div>} /></Routes>
    </MemoryRouter></ThemeProvider>,
  );
  expect(screen.getByRole('link', { name: /log in|sign in/i })).toBeInTheDocument();
});
```
(Adjust `useAuthStore.setState` shape to the real store.)

- [ ] **Step 3: Verify fail** — `npm test -- CheckoutPage.test` → FAIL.

- [ ] **Step 4: Implement CheckoutPage** per spec §6.5:
  - Empty cart → `EmptyState` with "Browse products" → `/products`.
  - Order summary (line items, tier-aware pricing via `useCartStore.refreshEstimate()` / `estimate`).
  - If `!user` (from `useAuthStore`): show a "Log in to place your order" panel with a `<Link to="/login" state={{ from: '/checkout' }}>` (cart is client-side Zustand and survives the redirect). After login the user returns to `/checkout`.
  - If `user`: a "Place order" button that calls the real `createQuote` action, then on success shows the celebratory confirmation (reuse existing modal pattern from CartPage) and navigates to `/quotes/:id`. Errors → toast.
  - Preserve the existing B2B flow: this only creates the DRAFT quote; proofing/pay happen later in `/quotes/:id` as today.
  - Responsive: summary stacks under form on mobile; sticky order-summary on desktop.

- [ ] **Step 5: Point cart CTA at checkout**

In `CartPage.tsx`, change the primary "Request a quote"/checkout CTA to navigate to `/checkout` (move the actual quote-creation into CheckoutPage). If CartPage currently creates the quote inline, relocate that logic to CheckoutPage and leave CartPage as cart management + "Proceed to checkout".

- [ ] **Step 6: Verify green** — `npm test -- CheckoutPage.test` (PASS), `npm test` (all), `npm run typecheck`.

- [ ] **Step 7: Commit**
```bash
git add frontend/src/pages/CheckoutPage.tsx frontend/src/pages/CheckoutPage.test.tsx frontend/src/pages/CartPage.tsx
git commit -m "feat(checkout): storefront checkout with login gate over quote flow"
```

---

## Phase 9 — Flip home route + retheme sweep

### Task 10: Activate HomePage as index + retheme remaining pages

**Files:**
- Modify: `frontend/src/App.tsx` (flip `index` to `HomePage`, ensure all new routes wired)
- Modify (retheme pass only, no logic change): `QuoteListPage.tsx`, `QuoteDetailPage.tsx`, `LoginPage.tsx`, `ProductionQueuePage.tsx`, `ProcurementPage.tsx`, `CatalogueAdminPage.tsx`, `ProductDesignerPage.tsx`

- [ ] **Step 1: Flip index route** to `<HomePage />` and confirm redirects + all page imports resolve.

- [ ] **Step 2: Retheme sweep** — visually verify each remaining page under Bold Studio tokens in BOTH themes; fix any hardcoded colors (replace literal hex with token utilities). "My Orders" label for `/quotes`. No behavioral/logic/store changes.

- [ ] **Step 3: Verify green** — `npm run typecheck`, `npm test` (all pass), `npm run build` (succeeds).

- [ ] **Step 4: Commit**
```bash
git add frontend/src/App.tsx frontend/src/pages
git commit -m "feat(ui): activate homepage + Bold Studio retheme sweep"
```

---

## Phase 10 — Mobile QA

### Task 11: Responsive QA pass (all pages, 360/768/1280)

**Files:** any page needing responsive fixes (touch as required).

- [ ] **Step 1: Launch app** (`npm run dev`, ensure backend on :8000) and check each route at widths 360, 768, 1280 (use the preview `preview_resize` presets mobile/tablet/desktop, or browser devtools): `/`, `/products`, `/products/:id`, `/design/:id`, `/cart`, `/checkout`, `/login`, `/quotes`, `/quotes/:id`, ops/admin.

- [ ] **Step 2: For each page verify:** no horizontal scroll; header collapses to drawer; grids reflow; tables become stacked cards; PDP gallery NOT sticky on mobile; tap targets ≥44px; both themes legible (contrast). Record issues.

- [ ] **Step 3: Fix** any responsive defects found (Tailwind responsive utilities; no new deps).

- [ ] **Step 4: Verify green** — `npm run typecheck`, `npm test`, `npm run build`.

- [ ] **Step 5: Commit**
```bash
git add frontend/src
git commit -m "fix(ui): responsive QA fixes across storefront pages"
```

---

## Self-Review Notes (author)

- **Spec coverage:** §3 theme→Task 1; §4 IA/routes→Task 5,10; §5 chrome→Task 3,4; §6.1 Home→Task 6; §6.2 catalogue→Task 8; §6.3 PDP→Task 7; §6.4 designer→Task 10 retheme; §6.5 cart/checkout→Task 9; §6.6/6.7 retheme→Task 10; §7 mobile→every task + Task 11; §8 quality→every task's verify step; data helpers→Task 2.
- **Sequencing:** index route stays on CataloguePage until Task 10 so the app is always runnable; App.tsx touched in Task 5 (routes+redirects, pages may be placeholder-guarded) and Task 10 (flip index).
- **No new backend:** all data via existing `/catalogue`, `/catalogue/{id}`, `/price-estimate`, and the existing quote-create action. Reviews are presentational.
- **Naming consistency:** `fetchCatalogue`/`fetchProduct`/`fetchTierPrices` (Task 2) used identically in Tasks 6-8; `TierPrice` shape `{ qty, unitPrice, currency }` consistent PDP↔lib.
