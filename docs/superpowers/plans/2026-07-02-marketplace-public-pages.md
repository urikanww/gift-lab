# Marketplace Public Pages Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rework the public-facing pages (Home, Catalogue, Product Detail, Designer, Header) into a dense marketplace-style storefront that categorizes products the way marketplaces do (Drinkware, Bags, Stationery…) instead of by print technique, removes "how it works"/benefit explainers, and gives each page a distinctive personalization-focused feature.

**Architecture:** Add a `category` column to `products` assigned automatically by a keyword classifier (backend), expose + filter + sort it through the existing public catalogue API, then rebuild the React public pages around the new taxonomy with denser layouts. Print-class (`CORE`/`SCRAPED_UV`/`MODEL_3D`) stays as an internal/admin concept only — it disappears from public UI.

**Tech Stack:** Laravel 11 (Pest tests), React 18 + TypeScript + Tailwind + framer-motion + zustand (Vitest + Testing Library). Repo layout: backend at repo root, SPA in `frontend/`.

**Commands used throughout:**
- Backend tests: `php artisan test --filter=<Name>` (run from repo root `D:\work\NexGen\gift-lab`)
- Frontend tests: `npm run test` (run from `frontend/`); single file: `npx vitest run src/pages/CataloguePage.test.tsx`
- Frontend types: `npm run typecheck` (from `frontend/`)

**The marketplace taxonomy (used everywhere; keys are stable slugs):**

| key | label | icon | blurb |
|---|---|---|---|
| `drinkware` | Drinkware | ☕ | Mugs, tumblers & bottles |
| `bags` | Bags & Totes | 👜 | Totes, pouches & carry-alls |
| `stationery` | Stationery & Office | ✏️ | Notebooks, pens & desk gear |
| `apparel` | Apparel | 👕 | Tees, caps & wearables |
| `tech` | Tech & Gadgets | 📱 | Grips, stands & accessories |
| `home` | Home & Living | 🏠 | Coasters, frames & decor |
| `accessories` | Keychains & Pins | 🔑 | Keychains, pins & charms |
| `toys` | Toys & Figurines | 🧸 | 3D-printed figures & fun |

Fallback when no keyword matches: `MODEL_3D` products → `toys`, everything else → `accessories`.

---

## File Structure

**Created:**
- `app/Services/Catalogue/CategoryClassifier.php` — name → category keyword classifier (pure, no I/O)
- `app/Console/Commands/BackfillProductCategories.php` — one-shot backfill for existing rows
- `database/migrations/2026_07_02_000022_add_category_to_products.php` — nullable indexed `category` column
- `tests/Unit/CategoryClassifierTest.php` — classifier unit tests

**Modified (backend):**
- `app/Models/Product.php` — fillable + saving-hook auto-assign
- `app/Http/Controllers/CatalogueController.php` — `category` filter + `sort` param
- `app/Http/Resources/ProductResource.php` — expose `category`
- `database/seeders/CoreCatalogueSeeder.php` — explicit category per seeded row (raw DB inserts bypass model hooks)
- `tests/Feature/CatalogueTest.php` — filter/sort/resource coverage
- `docs/API.md` — document new params

**Modified (frontend, all under `frontend/src/`):**
- `pages/LoginPage.tsx` + new `pages/LoginPage.test.tsx` — role-aware post-login landing (staff → `/catalogue-admin`)
- `components/SiteHeader.tsx` — ops nav links for staff roles (Task 0), staff "Quotes" label (Task 0b), Categories dropdown (Task 7)
- `pages/QuoteListPage.tsx` + new `pages/QuoteListPage.test.tsx` — staff copy + Company column (Task 0b; also touches `QuoteController`/`QuoteResource` backend)
- `types.ts` — `Product.category`
- `lib/categories.ts` — marketplace taxonomy (replaces print-class categories)
- `lib/catalogue.ts` + `lib/catalogue.test.ts` — options-object `fetchCatalogue` with category/q/sort
- `components/product/ProductCard.tsx` — category badge, hover "Personalize now" quick action, denser card
- `pages/CataloguePage.tsx` + test — category chip rail, sort, server-side search, dense 5-col grid
- `pages/HomePage.tsx` + test — compact search hero, category tiles, New-arrivals rail + Popular grid; explainer sections deleted
- `components/SiteHeader.tsx` + test — Categories dropdown menu; drawer category links
- `pages/ProductDetailPage.tsx` + test — category breadcrumb, live name-preview widget, print-method selector removed
- `pages/ProductDesignerPage.tsx`, `components/DesignerCanvas.tsx`, `stores/cartStore.ts` + `stores/cartStore.test.ts` — `?name=` prefill, qty + live unit-price sticky bar

---

### Task 0: Staff login redirect + ops navigation (pre-existing bug fix)

**Files:**
- Modify: `frontend/src/pages/LoginPage.tsx:7-28`
- Modify: `frontend/src/components/SiteHeader.tsx` (desktop nav ~line 58; mobile drawer ~line 276)
- Create: `frontend/src/pages/LoginPage.test.tsx`
- Test: `frontend/src/components/SiteHeader.test.tsx`

Bug: superadmin/ops sign in and get redirected to `/quotes` (the buyer surface) — `LoginPage` hardcodes the fallback — and the header renders **no links at all** to `/catalogue-admin`, `/production-queue`, `/procurement`, so staff can only reach their pages by typing URLs. Fix: role-aware post-login landing (staff → `/catalogue-admin`, the manageable-items gate) + persistent ops nav links for staff roles.

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/pages/LoginPage.test.tsx`:

```tsx
import { afterEach, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import LoginPage from './LoginPage';
import { useAuthStore } from '../stores/authStore';
import type { User } from '../types';

const initialStore = useAuthStore.getState();
afterEach(() => useAuthStore.setState(initialStore, true));

function stubLoginAs(role: User['role']) {
  useAuthStore.setState({
    error: null,
    login: async () => {
      useAuthStore.setState({
        user: { id: 1, company_id: role === 'buyer' ? 7 : null, name: 'U', email: 'u@x.test', role },
        status: 'ready',
        error: null,
      });
      return true;
    },
  } as any);
}

function renderLogin() {
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/login']}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/catalogue-admin" element={<div>GATE PAGE</div>} />
          <Route path="/quotes" element={<div>QUOTES PAGE</div>} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

async function submitCredentials() {
  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/email/i), 'someone@giftlab.local');
  await user.type(screen.getByLabelText(/password/i), 'secret');
  await user.click(screen.getByRole('button', { name: /sign in/i }));
}

it('lands staff on the catalogue gate after sign-in', async () => {
  stubLoginAs('staff_admin');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('GATE PAGE')).toBeInTheDocument());
});

it('lands superadmin on the catalogue gate after sign-in', async () => {
  stubLoginAs('superadmin');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('GATE PAGE')).toBeInTheDocument());
});

it('lands buyers on their quotes after sign-in', async () => {
  stubLoginAs('buyer');
  renderLogin();
  await submitCredentials();
  await waitFor(() => expect(screen.getByText('QUOTES PAGE')).toBeInTheDocument());
});
```

Append to `frontend/src/components/SiteHeader.test.tsx`:

```tsx
it('shows ops navigation links for staff roles', () => {
  useAuthStore.setState({
    user: { ...testUser, role: 'staff_admin', company_id: null },
    status: 'ready',
    error: null,
  });
  renderHeader();

  expect(screen.getByRole('link', { name: /catalogue gate/i })).toHaveAttribute('href', '/catalogue-admin');
  expect(screen.getByRole('link', { name: /production/i })).toHaveAttribute('href', '/production-queue');
  expect(screen.getByRole('link', { name: /procurement/i })).toHaveAttribute('href', '/procurement');
});

it('hides ops navigation from buyers and anonymous visitors', () => {
  renderHeader();
  expect(screen.queryByRole('link', { name: /catalogue gate/i })).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run (from `frontend/`): `npx vitest run src/pages/LoginPage.test.tsx src/components/SiteHeader.test.tsx`
Expected: staff/superadmin login tests FAIL (land on QUOTES PAGE); ops-nav test FAILS (no such links). Buyer test and pre-existing header tests pass.

- [ ] **Step 3: Implement the role-aware login redirect**

In `frontend/src/pages/LoginPage.tsx`:

1. Add the import: `import { isStaffRole } from '../lib/roles';`
2. Replace line 15 (`const from = ... ?? '/quotes';`) with:

```tsx
  const from = (location.state as LocationState | null)?.from;
```

3. Replace the `submit` handler's success branch (line 27):

```tsx
    if (ok) {
      // Role-aware landing: staff manage the catalogue gate; buyers see their
      // quotes. An explicit `from` (bounced off a protected route) still wins.
      const role = useAuthStore.getState().user?.role;
      navigate(from ?? (isStaffRole(role) ? '/catalogue-admin' : '/quotes'), { replace: true });
    }
```

- [ ] **Step 4: Implement the ops navigation**

In `frontend/src/components/SiteHeader.tsx`:

1. Add the import: `import { isStaffRole } from '../lib/roles';`
2. In the desktop nav (`<nav … aria-label="Primary">`), add directly after the `CATEGORIES.map(...)` loop (still inside the `<nav>`):

```tsx
          {isStaffRole(user?.role) && (
            <>
              <span className="mx-1 h-5 w-px bg-border" aria-hidden="true" />
              <NavLink to="/catalogue-admin" className={navLinkClass}>
                Catalogue Gate
              </NavLink>
              <NavLink to="/production-queue" className={navLinkClass}>
                Production
              </NavLink>
              <NavLink to="/procurement" className={navLinkClass}>
                Procurement
              </NavLink>
            </>
          )}
```

3. In `MobileDrawer`, add the same three `NavLink`s (each with `onClick={onClose}`) inside the bottom `border-t` section, directly above `<AccountLink user={user} onClick={onClose} />`, gated by the same `isStaffRole(user?.role) &&` check:

```tsx
              {isStaffRole(user?.role) && (
                <>
                  <NavLink to="/catalogue-admin" onClick={onClose} className={navLinkClass}>
                    Catalogue Gate
                  </NavLink>
                  <NavLink to="/production-queue" onClick={onClose} className={navLinkClass}>
                    Production
                  </NavLink>
                  <NavLink to="/procurement" onClick={onClose} className={navLinkClass}>
                    Procurement
                  </NavLink>
                </>
              )}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `npx vitest run src/pages/LoginPage.test.tsx src/components/SiteHeader.test.tsx` — all pass. Then `npm run typecheck` + full `npm run test`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/LoginPage.tsx frontend/src/pages/LoginPage.test.tsx frontend/src/components/SiteHeader.tsx frontend/src/components/SiteHeader.test.tsx
git commit -m "fix(frontend): role-aware login landing and ops navigation for staff"
```

---

### Task 0b: Staff-aware quote list (cosmetics + company column)

**Files:**
- Modify: `app/Http/Controllers/QuoteController.php:28-38`
- Modify: `app/Http/Resources/QuoteResource.php:20-35`
- Modify: `frontend/src/types.ts:110-123` (Quote interface)
- Modify: `frontend/src/pages/QuoteListPage.tsx`
- Modify: `frontend/src/components/SiteHeader.tsx` (`AccountLink`, ~line 159)
- Create: `frontend/src/pages/QuoteListPage.test.tsx`
- Test: `tests/Feature/QuoteFlowTest.php`, `frontend/src/components/SiteHeader.test.tsx`

Staff visiting `/quotes` currently see the buyer-voiced UI ("My Orders", "Track your gift orders…") over ALL companies' quotes, with no way to tell whose quote is whose. Fix: expose `company_name` on staff listings, add a Company column, staff copy, and label the header link "Quotes" for staff.

- [ ] **Step 1: Write the failing backend test**

Append to `tests/Feature/QuoteFlowTest.php` (the file already uses `Company`/`User`/`Quote` factories and `Sanctum::actingAs` — add any missing `use` imports at the top: `App\Models\Company`, `App\Models\Quote`, `App\Models\User`, `Laravel\Sanctum\Sanctum`):

```php
it('includes the company name on quote listings for staff', function (): void {
    $company = Company::factory()->create(['name' => 'Acme Gifts Pte Ltd']);
    Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson('/api/quotes')
        ->assertOk()
        ->assertJsonPath('data.0.company_name', 'Acme Gifts Pte Ltd');
});

it('omits the company name on buyer quote listings', function (): void {
    $company = Company::factory()->create();
    Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $response = $this->getJson('/api/quotes')->assertOk();
    expect($response->json('data.0'))->not->toHaveKey('company_name');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QuoteFlowTest`
Expected: first new test FAILS (`company_name` missing); second passes vacuously only after implementation — confirm it fails or errors now, then both pass after Step 3.

- [ ] **Step 3: Implement backend**

In `app/Http/Controllers/QuoteController.php`, replace the `index` query (lines 32-35) with:

```php
        $quotes = Quote::query()
            ->when(! $user->isStaff(), fn ($q) => $q->where('company_id', $user->company_id))
            // Staff see all companies — load the name so the UI can label rows.
            ->when($user->isStaff(), fn ($q) => $q->with('company'))
            ->latest()
            ->paginate(20);
```

In `app/Http/Resources/QuoteResource.php`, add directly after `'company_id' => $this->company_id,`:

```php
            // Present only when the relation is loaded (staff listings).
            'company_name' => $this->whenLoaded('company', fn () => $this->company->name),
```

Run: `php artisan test --filter=QuoteFlowTest` — expected PASS.

- [ ] **Step 4: Write the failing frontend tests**

Create `frontend/src/pages/QuoteListPage.test.tsx`:

```tsx
import { afterEach, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import QuoteListPage from './QuoteListPage';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';

const initialQuoteStore = useQuoteStore.getState();
const initialAuthStore = useAuthStore.getState();
afterEach(() => {
  useQuoteStore.setState(initialQuoteStore, true);
  useAuthStore.setState(initialAuthStore, true);
});

const sampleQuote = {
  id: 42,
  company_id: 7,
  company_name: 'Acme Gifts Pte Ltd',
  state: 'SENT',
  currency: 'SGD',
  subtotal: '100.00',
  delivery: '5.00',
  total: '105.00',
  price_snapshot_at: null,
  notes: null,
  created_at: '2026-07-01T00:00:00Z',
} as any;

function seedQuotes() {
  useQuoteStore.setState({
    quotes: [sampleQuote],
    loading: false,
    error: null,
    page: 1,
    lastPage: 1,
    fetchQuotes: async () => {},
  } as any);
}

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <QuoteListPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('shows the company column and staff copy for staff', () => {
  seedQuotes();
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'Ops', email: 'ops@x.test', role: 'staff_admin' },
    status: 'ready',
    error: null,
  });

  renderPage();

  expect(screen.getByText('Company')).toBeInTheDocument();
  // Rendered in both the desktop table and the mobile card list.
  expect(screen.getAllByText('Acme Gifts Pte Ltd').length).toBeGreaterThanOrEqual(1);
  expect(screen.getByText(/across every company/i)).toBeInTheDocument();
});

it('hides the company column and keeps buyer copy for buyers', () => {
  seedQuotes();
  useAuthStore.setState({
    user: { id: 2, company_id: 7, name: 'Ada', email: 'ada@x.test', role: 'buyer' },
    status: 'ready',
    error: null,
  });

  renderPage();

  expect(screen.queryByText('Company')).not.toBeInTheDocument();
  expect(screen.queryByText('Acme Gifts Pte Ltd')).not.toBeInTheDocument();
  expect(screen.getByText(/track your gift orders/i)).toBeInTheDocument();
});
```

Append to `frontend/src/components/SiteHeader.test.tsx`:

```tsx
it('labels the quotes link "Quotes" for staff and "My Orders" for buyers', () => {
  useAuthStore.setState({
    user: { ...testUser, role: 'staff_admin', company_id: null },
    status: 'ready',
    error: null,
  });
  renderHeader();

  expect(screen.getByRole('link', { name: 'Quotes' })).toHaveAttribute('href', '/quotes');
  expect(screen.queryByRole('link', { name: /my orders/i })).not.toBeInTheDocument();
});
```

Run: `npx vitest run src/pages/QuoteListPage.test.tsx src/components/SiteHeader.test.tsx`
Expected: new tests FAIL (no Company column, no staff copy, link still "My Orders").

- [ ] **Step 5: Implement frontend**

In `frontend/src/types.ts`, add to the `Quote` interface after `company_id: number;`:

```ts
  /** Only present on staff listings (relation-loaded server-side). */
  company_name?: string;
```

In `frontend/src/components/SiteHeader.tsx`, replace `AccountLink` with:

```tsx
function AccountLink({ user, onClick }: { user: User | null; onClick?: () => void }) {
  return user ? (
    <NavLink to="/quotes" onClick={onClick} className={navLinkClass}>
      {isStaffRole(user.role) ? 'Quotes' : 'My Orders'}
    </NavLink>
  ) : (
    <NavLink to="/login" onClick={onClick} className={navLinkClass}>
      Log in
    </NavLink>
  );
}
```

(`isStaffRole` is already imported by Task 0.)

In `frontend/src/pages/QuoteListPage.tsx`:

1. Add imports:

```tsx
import { useAuthStore } from '../stores/authStore';
import { isStaffRole } from '../lib/roles';
```

2. Inside `QuoteListPage`, after `const shouldAnimate = ...`:

```tsx
  const staff = isStaffRole(useAuthStore((s) => s.user?.role));
```

3. Replace the subtitle `<p>` (line 36):

```tsx
        <p className="mt-1 text-sm text-fg-muted">
          {staff
            ? 'All customer quotes, newest first — across every company.'
            : 'Track your gift orders from request through production.'}
        </p>
```

4. Replace the `EmptyState` block (lines 44-52) — staff get no "browse" CTA, and the buyer CTA points at the canonical `/products` route (the old `/catalogue` only worked via redirect):

```tsx
        <EmptyState
          title="No quotes yet"
          description={
            staff
              ? 'Customer quote requests will appear here as they come in.'
              : 'Once you request a quote from your cart, it will appear here.'
          }
          action={
            staff ? undefined : (
              <Button variant="primary" onClick={() => navigate('/products')}>
                Browse catalogue
              </Button>
            )
          }
        />
```

5. In the desktop table header, add after the `Quote` `<th>`:

```tsx
                    {staff && (
                      <th scope="col" className="px-5 py-3 font-medium">
                        Company
                      </th>
                    )}
```

6. Pass the flag to both row renderers:
   - `<QuoteRow key={q.id} quote={q} animate={shouldAnimate} showCompany={staff} />`
   - `<QuoteCard key={q.id} quote={q} showCompany={staff} />`

7. Update `QuoteRow` — signature and a new cell after the Quote `<td>`:

```tsx
function QuoteRow({
  quote,
  animate,
  showCompany,
}: {
  quote: Quote;
  animate: boolean;
  showCompany: boolean;
}) {
```

```tsx
      {showCompany && (
        <td className="px-5 py-4 text-fg-muted">
          {quote.company_name ?? `Company #${quote.company_id}`}
        </td>
      )}
```

8. Update `QuoteCard` — signature and a company line under the date `<p>`:

```tsx
function QuoteCard({ quote, showCompany }: { quote: Quote; showCompany: boolean }) {
```

```tsx
            {showCompany && (
              <p className="mt-0.5 text-xs text-fg-muted">
                {quote.company_name ?? `Company #${quote.company_id}`}
              </p>
            )}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npx vitest run src/pages/QuoteListPage.test.tsx src/components/SiteHeader.test.tsx` — all pass.
Then `npm run typecheck`, full `npm run test`, and `php artisan test` — all green.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/QuoteController.php app/Http/Resources/QuoteResource.php tests/Feature/QuoteFlowTest.php frontend/src/types.ts frontend/src/components/SiteHeader.tsx frontend/src/components/SiteHeader.test.tsx frontend/src/pages/QuoteListPage.tsx frontend/src/pages/QuoteListPage.test.tsx
git commit -m "feat(quotes): staff-aware quote list with company column"
```

---

### Task 1: Backend — CategoryClassifier service

**Files:**
- Create: `app/Services/Catalogue/CategoryClassifier.php`
- Test: `tests/Unit/CategoryClassifierTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/CategoryClassifierTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ProductClass;
use App\Services\Catalogue\CategoryClassifier;

it('classifies product names into marketplace categories', function (string $name, string $expected): void {
    expect((new CategoryClassifier())->classify($name, ProductClass::Core))->toBe($expected);
})->with([
    ['Ceramic Mug 11oz', 'drinkware'],
    // 'tee' must NOT match inside 'Stainless' / 'Steel' — word boundaries required.
    ['Stainless Tumbler 500ml', 'drinkware'],
    ['Glass Water Bottle 600ml', 'drinkware'],
    ['Canvas Tote Bag', 'bags'],
    ['A5 Hardcover Notebook', 'stationery'],
    ['Ballpoint Pen (Metal)', 'stationery'],
    ['Cotton T-Shirt', 'apparel'],
    ['Silicone Phone Grip', 'tech'],
    ['Bamboo Coaster', 'home'],
    ['Enamel Keychain', 'accessories'],
    ['Articulated Dragon Fidget', 'toys'],
]);

it('falls back by product class when no keyword matches', function (): void {
    $classifier = new CategoryClassifier();

    expect($classifier->classify('Mystery Object', ProductClass::Model3d))->toBe('toys')
        ->and($classifier->classify('Mystery Object', ProductClass::Core))->toBe('accessories')
        ->and($classifier->classify('Mystery Object', ProductClass::ScrapedUv))->toBe('accessories');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CategoryClassifierTest`
Expected: FAIL with `Class "App\Services\Catalogue\CategoryClassifier" not found`

- [ ] **Step 3: Write the implementation**

Create `app/Services/Catalogue/CategoryClassifier.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\ProductClass;

/**
 * Maps a product name to the public marketplace category (how buyers shop),
 * decoupled from the internal print-class taxonomy (how items are produced).
 * First keyword match in declaration order wins; word-boundary matching so
 * e.g. 'tee' never fires inside 'Steel'.
 */
class CategoryClassifier
{
    /** Stable public category slugs, in display order. */
    public const CATEGORIES = [
        'drinkware', 'bags', 'stationery', 'apparel', 'tech', 'home', 'accessories', 'toys',
    ];

    private const KEYWORDS = [
        'drinkware' => ['mug', 'tumbler', 'bottle', 'cup', 'flask', 'thermos', 'stein', 'glass'],
        'bags' => ['tote', 'bag', 'pouch', 'backpack', 'sling', 'drawstring'],
        'stationery' => ['notebook', 'pen', 'pencil', 'journal', 'planner', 'notepad', 'bookmark', 'ruler', 'eraser'],
        'apparel' => ['t-shirt', 'tee', 'shirt', 'hoodie', 'cap', 'hat', 'sock', 'apron', 'jersey'],
        'tech' => ['phone', 'grip', 'charger', 'cable', 'usb', 'mouse', 'stand', 'holder', 'earbud', 'headphone', 'speaker', 'laptop'],
        'home' => ['coaster', 'candle', 'vase', 'planter', 'frame', 'organiser', 'organizer', 'tray', 'clock', 'ornament', 'magnet'],
        'accessories' => ['keychain', 'keyring', 'key ring', 'pin', 'badge', 'lanyard', 'strap', 'charm', 'carabiner', 'wristband'],
        'toys' => ['figurine', 'figure', 'toy', 'dragon', 'articulated', 'puzzle', 'dice', 'miniature', 'fidget'],
    ];

    public function classify(string $name, ProductClass $class): string
    {
        foreach (self::KEYWORDS as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/\b'.preg_quote($keyword, '/').'\b/i', $name) === 1) {
                    return $category;
                }
            }
        }

        return $class === ProductClass::Model3d ? 'toys' : 'accessories';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CategoryClassifierTest`
Expected: PASS (2 tests, 13 assertions via dataset)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Catalogue/CategoryClassifier.php tests/Unit/CategoryClassifierTest.php
git commit -m "feat(catalogue): keyword classifier for marketplace categories"
```

---

### Task 2: Backend — category column, auto-assign hook, seeder, backfill

**Files:**
- Create: `database/migrations/2026_07_02_000022_add_category_to_products.php`
- Create: `app/Console/Commands/BackfillProductCategories.php`
- Modify: `app/Models/Product.php` (fillable list ~line 33; `booted()` ~line 83)
- Modify: `database/seeders/CoreCatalogueSeeder.php` (catalogue array ~line 42, insert ~line 87)
- Test: `tests/Feature/CatalogueTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CatalogueTest.php`:

```php
it('auto-assigns a marketplace category on save', function (): void {
    $mug = Product::factory()->create(['name' => 'Ceramic Mug 11oz', 'publish_state' => 'PUBLISHED']);
    $tote = Product::factory()->create(['name' => 'Canvas Tote Bag', 'publish_state' => 'PUBLISHED']);

    expect($mug->category)->toBe('drinkware')
        ->and($tote->category)->toBe('bags');
});

it('keeps an explicitly set category instead of reclassifying', function (): void {
    $product = Product::factory()->create([
        'name' => 'Ceramic Mug 11oz',
        'category' => 'home',
        'publish_state' => 'PUBLISHED',
    ]);

    expect($product->fresh()->category)->toBe('home');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CatalogueTest`
Expected: the two new tests FAIL (`category` is null / column missing); pre-existing tests still pass.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_02_000022_add_category_to_products.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public marketplace category (drinkware, bags, …) — how buyers browse.
 * Orthogonal to `class`, which stays the internal production taxonomy.
 * Nullable so the model saving-hook (or catalogue:categorize) fills it in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('category', 32)->nullable()->index()->after('class');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
```

- [ ] **Step 4: Wire the model**

In `app/Models/Product.php`:

1. In `$fillable`, insert `'category',` directly after `'class',`.
2. In `booted()`, add this block immediately AFTER the existing slug-generation `static::saving(...)` closure (so both hooks run; order doesn't matter between them):

```php
        // Marketplace category: assigned once from the name when absent, kept
        // when set explicitly (admin/seed overrides win over the classifier).
        static::saving(function (Product $product): void {
            if ($product->category === null || $product->category === '') {
                $product->category = app(\App\Services\Catalogue\CategoryClassifier::class)
                    ->classify((string) $product->name, $product->class);
            }
        });
```

3. Add the property to the class docblock: `@property string|null $category`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=CatalogueTest`
Expected: PASS (all tests in the file).

- [ ] **Step 6: Seed categories explicitly (raw inserts bypass model hooks)**

In `database/seeders/CoreCatalogueSeeder.php`:

1. Update the comment above the `$catalogue` array to:
   `// [name, base_cost, print_method, dims(mm), weight(g), category, variants[[color,size,stock,delta]]]`
2. Add the category as the 6th element of each row (before the variants array):
   - `'Ceramic Mug 11oz'` → `'drinkware'`
   - `'Stainless Tumbler 500ml'` → `'drinkware'`
   - `'Canvas Tote Bag'` → `'bags'`
   - `'Bamboo Coaster'` → `'home'`
   - `'A5 Hardcover Notebook'` → `'stationery'`
   - `'Ballpoint Pen (Metal)'` → `'stationery'`
   - `'Glass Water Bottle 600ml'` → `'drinkware'`
   - `'Cotton T-Shirt'` → `'apparel'`
   - `'Silicone Phone Grip'` → `'tech'`
   - `'Enamel Keychain'` → `'accessories'`

   Example row after the change:

```php
            ['Ceramic Mug 11oz', 3.20, 'UV', ['l' => 95, 'w' => 82, 'h' => 95], 320, 'drinkware', [
                ['White', 'STD', 240, 0.00],
                ['Black', 'STD', 180, 0.50],
            ]],
```

3. Update the destructuring foreach to include it:

```php
        foreach ($catalogue as [$name, $baseCost, $method, $dims, $weight, $category, $variants]) {
```

4. Add `'category' => $category,` to the `DB::table('products')->insertGetId([...])` array, directly after `'class' => 'CORE',`.

- [ ] **Step 7: Create the backfill command**

Create `app/Console/Commands/BackfillProductCategories.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalogue\CategoryClassifier;
use Illuminate\Console\Command;

/**
 * One-shot backfill: classify every product that has no marketplace category
 * yet (rows created before the category column existed, or raw-inserted).
 */
class BackfillProductCategories extends Command
{
    protected $signature = 'catalogue:categorize {--force : Re-classify ALL products, overwriting existing categories}';

    protected $description = 'Assign marketplace categories to products via the keyword classifier';

    public function handle(CategoryClassifier $classifier): int
    {
        $query = Product::withTrashed()
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('category'));

        $count = 0;
        $query->chunkById(200, function ($products) use ($classifier, &$count): void {
            foreach ($products as $product) {
                $product->timestamps = false;
                $product->forceFill([
                    'category' => $classifier->classify((string) $product->name, $product->class),
                ])->saveQuietly();
                $count++;
            }
        });

        $this->info("Categorized {$count} product(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: Run migration + backfill locally, verify**

```bash
php artisan migrate
php artisan catalogue:categorize
```
Expected: migration runs clean; command prints `Categorized N product(s).` Then run the full backend suite: `php artisan test` — expected all green.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_02_000022_add_category_to_products.php app/Models/Product.php app/Console/Commands/BackfillProductCategories.php database/seeders/CoreCatalogueSeeder.php tests/Feature/CatalogueTest.php
git commit -m "feat(catalogue): marketplace category column with auto-assignment and backfill"
```

---

### Task 3: Backend — catalogue API category filter, sort, resource field

**Files:**
- Modify: `app/Http/Controllers/CatalogueController.php:23-40`
- Modify: `app/Http/Resources/ProductResource.php:22-47`
- Modify: `docs/API.md:30-35`
- Test: `tests/Feature/CatalogueTest.php`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CatalogueTest.php`:

```php
it('filters the catalogue by marketplace category', function (): void {
    Product::factory()->create(['name' => 'Ceramic Mug 11oz', 'publish_state' => 'PUBLISHED']);
    Product::factory()->create(['name' => 'Canvas Tote Bag', 'publish_state' => 'PUBLISHED']);

    $response = $this->getJson('/api/catalogue?category=drinkware');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Ceramic Mug 11oz')
        ->and($names)->not->toContain('Canvas Tote Bag');
});

it('exposes the marketplace category on the product resource', function (): void {
    Product::factory()->create(['name' => 'Ceramic Mug 11oz', 'publish_state' => 'PUBLISHED']);

    $this->getJson('/api/catalogue')
        ->assertOk()
        ->assertJsonPath('data.0.category', 'drinkware');
});

it('sorts the catalogue by newest first when requested', function (): void {
    Product::factory()->create(['name' => 'Old Mug', 'publish_state' => 'PUBLISHED', 'created_at' => now()->subDay()]);
    Product::factory()->create(['name' => 'New Mug', 'publish_state' => 'PUBLISHED', 'created_at' => now()]);

    $response = $this->getJson('/api/catalogue?sort=newest');

    expect($response->json('data.0.name'))->toBe('New Mug');
});

it('sorts the catalogue by price', function (): void {
    Product::factory()->create(['name' => 'Pricey Mug', 'base_cost' => 50, 'publish_state' => 'PUBLISHED']);
    Product::factory()->create(['name' => 'Cheap Mug', 'base_cost' => 1, 'publish_state' => 'PUBLISHED']);

    expect($this->getJson('/api/catalogue?sort=price_asc')->json('data.0.name'))->toBe('Cheap Mug')
        ->and($this->getJson('/api/catalogue?sort=price_desc')->json('data.0.name'))->toBe('Pricey Mug');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CatalogueTest`
Expected: the four new tests FAIL (category filter ignored / `category` key missing / order still alphabetical).

- [ ] **Step 3: Implement controller + resource**

In `app/Http/Controllers/CatalogueController.php`, replace the whole `index` method with:

```php
    public function index(Request $request): AnonymousResourceCollection
    {
        // `sort=price_*` orders by base_cost: the public from_price is a
        // monotonic margin over base_cost, so relative order is preserved
        // without leaking the internal cost itself.
        $sort = $request->string('sort')->toString();

        $query = Product::query()
            ->published()
            ->when(
                $request->filled('class'),
                fn ($q) => $q->where('class', $request->string('class')->toString())
            )
            ->when(
                $request->filled('category'),
                fn ($q) => $q->where('category', $request->string('category')->toString())
            )
            ->when(
                $request->filled('q'),
                fn ($q) => $q->where('name', 'like', '%'.$request->string('q')->toString().'%')
            )
            ->with('variants');

        match ($sort) {
            'price_asc' => $query->orderBy('base_cost')->orderBy('name'),
            'price_desc' => $query->orderByDesc('base_cost')->orderBy('name'),
            'newest' => $query->orderByDesc('created_at')->orderBy('name'),
            default => $query->orderBy('name'),
        };

        return ProductResource::collection(
            $query->paginate(24)->appends($request->query())
        );
    }
```

In `app/Http/Resources/ProductResource.php`, add directly after the `'class' => $this->class->value,` line:

```php
            // Public marketplace category (how buyers browse) — see CategoryClassifier.
            'category' => $this->category,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CatalogueTest`
Expected: PASS. Then `php artisan test` for the full suite — all green.

- [ ] **Step 5: Update the API doc**

In `docs/API.md`, replace the line:

```
### `GET /catalogue?q=&class=&page=`
Only `PUBLISHED` products. `200` → paginated `ProductResource`.
```

with:

```
### `GET /catalogue?q=&category=&class=&sort=&page=`
Only `PUBLISHED` products. `200` → paginated `ProductResource`.
`category` = marketplace category slug (`drinkware|bags|stationery|apparel|tech|home|accessories|toys`).
`sort` = `name` (default) | `newest` | `price_asc` | `price_desc` (price sorts use `base_cost`, monotonic with the public price).
```

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/CatalogueController.php app/Http/Resources/ProductResource.php docs/API.md tests/Feature/CatalogueTest.php
git commit -m "feat(catalogue): category filter and sort on the public catalogue API"
```

---

### Task 4: Frontend — data layer (types, categories lib, catalogue lib)

**Files:**
- Modify: `frontend/src/types.ts:54-73`
- Modify: `frontend/src/lib/categories.ts` (full rewrite)
- Modify: `frontend/src/lib/catalogue.ts:4-10`
- Test: `frontend/src/lib/catalogue.test.ts`

- [ ] **Step 1: Write the failing tests**

In `frontend/src/lib/catalogue.test.ts`, replace the `'fetchCatalogue passes page param'` test with:

```ts
  it('fetchCatalogue passes page, category, q and sort params', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1 } });
    await fetchCatalogue({ page: 2, category: 'drinkware', q: 'mug', sort: 'newest' });
    expect(api.get).toHaveBeenCalledWith('/catalogue', {
      params: { page: 2, category: 'drinkware', q: 'mug', sort: 'newest' },
    });
  });

  it('fetchCatalogue omits empty params and defaults page to 1', async () => {
    (api.get as any).mockResolvedValue({ data: { data: [], current_page: 1, last_page: 1 } });
    await fetchCatalogue();
    expect(api.get).toHaveBeenCalledWith('/catalogue', { params: { page: 1 } });
  });
```

- [ ] **Step 2: Run tests to verify they fail**

Run (from `frontend/`): `npx vitest run src/lib/catalogue.test.ts`
Expected: FAIL — current `fetchCatalogue(page, productClass)` signature doesn't accept an options object.

- [ ] **Step 3: Implement the data layer**

In `frontend/src/types.ts`, add to the `Product` interface directly after the `class: ProductClass;` line:

```ts
  /** Public marketplace category slug (drinkware, bags, …); null pre-backfill. */
  category?: string | null;
```

Replace the entire content of `frontend/src/lib/categories.ts` with:

```ts
// Public marketplace taxonomy — how buyers browse. Decoupled from the internal
// print-class (CORE/SCRAPED_UV/MODEL_3D), which never appears in public UI.
// Keys mirror backend App\Services\Catalogue\CategoryClassifier::CATEGORIES.

export interface Category {
  key: string;
  label: string;
  icon: string;
  blurb: string;
}

export const CATEGORIES: Category[] = [
  { key: 'drinkware', label: 'Drinkware', icon: '☕', blurb: 'Mugs, tumblers & bottles' },
  { key: 'bags', label: 'Bags & Totes', icon: '👜', blurb: 'Totes, pouches & carry-alls' },
  { key: 'stationery', label: 'Stationery & Office', icon: '✏️', blurb: 'Notebooks, pens & desk gear' },
  { key: 'apparel', label: 'Apparel', icon: '👕', blurb: 'Tees, caps & wearables' },
  { key: 'tech', label: 'Tech & Gadgets', icon: '📱', blurb: 'Grips, stands & accessories' },
  { key: 'home', label: 'Home & Living', icon: '🏠', blurb: 'Coasters, frames & decor' },
  { key: 'accessories', label: 'Keychains & Pins', icon: '🔑', blurb: 'Keychains, pins & charms' },
  { key: 'toys', label: 'Toys & Figurines', icon: '🧸', blurb: '3D-printed figures & fun' },
];

export function categoryLabel(key: string | null | undefined): string {
  return CATEGORIES.find((c) => c.key === key)?.label ?? 'Gifts';
}
```

In `frontend/src/lib/catalogue.ts`, replace the existing `fetchCatalogue` function (and its comment) with:

```ts
export type CatalogueSort = 'name' | 'newest' | 'price_asc' | 'price_desc';

export interface CatalogueQuery {
  page?: number;
  /** Marketplace category slug (see lib/categories.ts). */
  category?: string;
  /** Server-side name search — keeps pagination valid across all pages. */
  q?: string;
  sort?: CatalogueSort;
}

export function fetchCatalogue(query: CatalogueQuery = {}): Promise<Paginated<Product>> {
  // All filtering/sorting is server-side: the catalogue paginates at 24/page,
  // so client-side filtering over one loaded page would hide later-page matches.
  const params: Record<string, string | number> = { page: query.page ?? 1 };
  if (query.category) params.category = query.category;
  if (query.q?.trim()) params.q = query.q.trim();
  if (query.sort && query.sort !== 'name') params.sort = query.sort;
  return api.get<Paginated<Product>>('/catalogue', { params }).then((r) => r.data);
}
```

- [ ] **Step 4: Fix the three call sites so typecheck passes**

These are updated properly in later tasks, but the signature change breaks them now — apply the minimal mechanical fix:

- `frontend/src/pages/HomePage.tsx:47`: `fetchCatalogue(1)` → `fetchCatalogue({})`
- `frontend/src/pages/CataloguePage.tsx:41`: `fetchCatalogue(target, cls)` → `fetchCatalogue({ page: target, category: cls || undefined })` — and since `class` filtering is now dead, this page is fully rewritten in Task 6; only make it compile here.
- `frontend/src/pages/ProductDetailPage.tsx:117`: `fetchCatalogue(1)` → `fetchCatalogue({})`
- `frontend/src/pages/ProductDetailPage.tsx:274` uses `categoryLabel(product.class)` and `:447` — `categoryLabel` now takes a string; `product.class` is a string union, so it still compiles (falls back to `'Gifts'`); leave for Task 8.
- `frontend/src/pages/CataloguePage.tsx` imports `categoryLabel` and uses `CATEGORIES.map((c) => c.key)` as `ProductClass` — replace the `CLASS_KEYS`/`parseClass` block with `const CLASS_KEYS = new Set<string>(CATEGORIES.map((c) => c.key));` and `function parseClass(value: string | null): string { return value && CLASS_KEYS.has(value) ? value : ''; }`, and change the `classFilter` state type from `'' | ProductClass` to `string` (remove the now-unused `ProductClass` import if flagged).
- `frontend/src/components/SiteHeader.tsx:62-66,279-283` renders `c.icon`/`c.label` and links `?class=${c.key}` — compiles unchanged (keys are now category slugs; links are corrected in Task 7).
- `frontend/src/components/product/ProductCard.tsx` — compiles unchanged.

Run: `npm run typecheck` (from `frontend/`) — expected: no errors.

- [ ] **Step 5: Run tests to verify they pass**

Run: `npx vitest run src/lib/catalogue.test.ts`
Expected: PASS. Then run the full frontend suite `npm run test` — pre-existing page tests must still pass (they mock `fetchCatalogue` wholesale, so the signature change is transparent).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/types.ts frontend/src/lib/categories.ts frontend/src/lib/catalogue.ts frontend/src/lib/catalogue.test.ts frontend/src/pages/HomePage.tsx frontend/src/pages/CataloguePage.tsx frontend/src/pages/ProductDetailPage.tsx
git commit -m "feat(frontend): marketplace category taxonomy in the data layer"
```

---

### Task 5: Frontend — ProductCard rework (category badge + Personalize quick action)

**Files:**
- Modify: `frontend/src/components/product/ProductCard.tsx`

The card gets: square image (denser grids), marketplace-category badge instead of the print-class badge, a hover/focus "Personalize now" overlay linking straight to the designer (the marketplace hook: every item is one click from personalization), tighter `p-3` body.

- [ ] **Step 1: Replace the card implementation**

In `frontend/src/components/product/ProductCard.tsx`:

1. Replace the imports block (lines 1-6) with:

```tsx
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { safeHref } from '../../lib/safeHref';
import { designPath } from '../../lib/catalogue';
import { categoryLabel } from '../../lib/categories';
import { Badge, Skeleton } from '../../ui';
import { Motion, staggerItem } from '../../motion';
import type { Product } from '../../types';
```

2. Delete the `CLASS_LABELS` and `CLASS_TONE` constants (lines 8-18).

3. In `CardSkeleton`, change `aspect-[4/3]` to `aspect-square` and `p-4` to `p-3`.

4. Replace the `ProductCard` function entirely with:

```tsx
export function ProductCard({ product, to, showMeta = false }: ProductCardProps) {
  return (
    <Motion variants={staggerItem} className="h-full">
      {/* Quick-action link is a SIBLING of the card link (never nested <a>). */}
      <div className="group relative h-full">
        <Link
          to={to}
          className="flex h-full flex-col overflow-hidden rounded-lg border border-border bg-surface shadow-card transition-shadow duration-base ease-standard hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
        >
          <div className="relative aspect-square w-full overflow-hidden bg-surface-2">
            <CardImage product={product} />
            {showMeta && product.category && (
              <div className="absolute left-2 top-2">
                <Badge tone="brand" size="sm">
                  {categoryLabel(product.category)}
                </Badge>
              </div>
            )}
          </div>
          <div className="flex flex-1 flex-col gap-0.5 p-3">
            <h3 className="line-clamp-2 text-sm font-medium leading-snug text-fg transition-colors duration-fast group-hover:text-primary">
              {product.name}
            </h3>
            {showMeta && product.creator_credit && (
              <p className="text-xs text-fg-subtle">by {product.creator_credit}</p>
            )}
            <p className="mt-auto pt-1.5 text-sm">
              <span className="text-2xs uppercase tracking-wide text-fg-subtle">from </span>
              <span className="font-semibold text-fg">
                {product.currency} {product.from_price.toFixed(2)}
              </span>
            </p>
          </div>
        </Link>
        <Link
          to={designPath(product)}
          aria-label={`Personalize ${product.name}`}
          className="absolute inset-x-2 bottom-2 z-raised translate-y-1 rounded-md bg-primary/95 px-3 py-1.5 text-center text-xs font-semibold text-primary-fg opacity-0 shadow-md transition-all duration-base group-hover:translate-y-0 group-hover:opacity-100 focus-visible:translate-y-0 focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring motion-reduce:transition-none motion-reduce:group-hover:translate-y-0"
        >
          🎨 Personalize now
        </Link>
      </div>
    </Motion>
  );
}
```

- [ ] **Step 2: Verify**

Run: `npm run typecheck` then `npm run test` (from `frontend/`).
Expected: both green — `CataloguePage.test.tsx` asserts the card link by role/name, unaffected; there is no `ProductClass` import left in this file.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/product/ProductCard.tsx
git commit -m "feat(frontend): marketplace product card with personalize quick action"
```

---

### Task 6: Frontend — CataloguePage rework (dense marketplace browse)

**Files:**
- Modify: `frontend/src/pages/CataloguePage.tsx` (full rewrite)
- Test: `frontend/src/pages/CataloguePage.test.tsx`

Page identity: dense browsing surface. Big editorial hero is removed; instead a slim toolbar (result count + sort + search), a horizontally scrollable category chip rail, a 2/3/4/5-column `gap-4` grid, and server-side search so pagination always works. URL params: `?category=`, `?q=`, `?sort=`.

- [ ] **Step 1: Write the failing tests**

Append to `frontend/src/pages/CataloguePage.test.tsx` inside the existing `describe`:

```tsx
  it('shows the marketplace category rail and filters on click', async () => {
    get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });

    renderPage();

    await waitFor(() => expect(screen.getByRole('button', { name: /drinkware/i })).toBeInTheDocument());
    get.mockClear();
    screen.getByRole('button', { name: /drinkware/i }).click();
    await waitFor(() =>
      expect(get).toHaveBeenCalledWith('/catalogue', {
        params: expect.objectContaining({ category: 'drinkware' }),
      }),
    );
  });

  it('offers marketplace sort options', async () => {
    get.mockResolvedValue({ data: { data: [], meta: { current_page: 1, last_page: 1, total: 0 } } });

    renderPage();

    await waitFor(() =>
      expect(screen.getByLabelText(/sort/i)).toBeInTheDocument(),
    );
    expect(screen.getByRole('option', { name: /price: low to high/i })).toBeInTheDocument();
  });
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/pages/CataloguePage.test.tsx`
Expected: the two new tests FAIL (no category buttons, no sort select). The two pre-existing tests pass.

- [ ] **Step 3: Rewrite the page**

Replace the entire content of `frontend/src/pages/CataloguePage.tsx` with:

```tsx
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiError } from '../lib/api';
import { fetchCatalogue, productPath, type CatalogueSort } from '../lib/catalogue';
import { CATEGORIES } from '../lib/categories';
import { Button, EmptyState, Input, Select, cn } from '../ui';
import { ErrorState } from '../components/ui/States';
import { ProductCard, CardSkeleton } from '../components/product/ProductCard';
import { Motion, staggerContainer } from '../motion';
import type { Product } from '../types';

const SORTS: { value: CatalogueSort; label: string }[] = [
  { value: 'name', label: 'Name (A–Z)' },
  { value: 'newest', label: 'Newest' },
  { value: 'price_asc', label: 'Price: low to high' },
  { value: 'price_desc', label: 'Price: high to low' },
];

const CATEGORY_KEYS = new Set(CATEGORIES.map((c) => c.key));
const SORT_KEYS = new Set<string>(SORTS.map((s) => s.value));

const GRID = 'grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5';

export default function CataloguePage() {
  const [searchParams, setSearchParams] = useSearchParams();

  // URL is the single source of truth for all filters (shareable/bookmarkable).
  const query = searchParams.get('q') ?? '';
  const rawCategory = searchParams.get('category');
  const category = rawCategory && CATEGORY_KEYS.has(rawCategory) ? rawCategory : '';
  const rawSort = searchParams.get('sort');
  const sort: CatalogueSort = rawSort && SORT_KEYS.has(rawSort) ? (rawSort as CatalogueSort) : 'name';

  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  const load = async (target: number, isActive: () => boolean = () => true) => {
    setLoading(true);
    setError(null);
    try {
      const data = await fetchCatalogue({ page: target, category: category || undefined, q: query, sort });
      if (!isActive()) return;
      setProducts(data.data);
      setPage(data.meta?.current_page ?? target);
      setLastPage(data.meta?.last_page ?? 1);
      setTotal(data.meta?.total ?? data.data.length);
    } catch (err) {
      if (isActive()) setError(apiError(err));
    } finally {
      if (isActive()) setLoading(false);
    }
  };

  // Server-side search/filter/sort: reload page 1 whenever any input changes.
  // Text input is debounced so we don't fire a request per keystroke.
  useEffect(() => {
    let active = true;
    const timer = setTimeout(() => void load(1, () => active), query ? 250 : 0);
    return () => {
      active = false;
      clearTimeout(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, category, sort]);

  const setParam = (key: string, value: string) => {
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        if (value) params.set(key, value);
        else params.delete(key);
        return params;
      },
      { replace: true },
    );
  };

  const hasActiveFilter = query.trim() !== '' || category !== '' || sort !== 'name';
  const clearFilters = () => setSearchParams({}, { replace: true });

  return (
    <div className="flex flex-col gap-5">
      {/* ── Slim toolbar: title + count + search + sort ──────────────────── */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="font-display text-2xl text-fg sm:text-3xl">Marketplace</h1>
          <p className="text-sm text-fg-muted" role="status">
            {loading ? 'Loading…' : `${total} customisable gift${total === 1 ? '' : 's'}`}
          </p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
          <div className="sm:w-64">
            <Input
              type="search"
              label="Search"
              placeholder="Search all gifts…"
              value={query}
              onChange={(e) => setParam('q', e.target.value)}
            />
          </div>
          <div className="sm:w-48">
            <Select label="Sort by" value={sort} onChange={(e) => setParam('sort', e.target.value)}>
              {SORTS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </Select>
          </div>
          {hasActiveFilter && (
            <Button variant="ghost" size="md" onClick={clearFilters}>
              Clear
            </Button>
          )}
        </div>
      </div>

      {/* ── Category chip rail (sticky, horizontally scrollable) ─────────── */}
      <div className="sticky top-16 z-raised -mx-4 border-y border-border bg-bg/85 px-4 py-2 backdrop-blur-md sm:top-16">
        <div
          className="flex gap-2 overflow-x-auto pb-0.5 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
          role="group"
          aria-label="Shop by category"
        >
          <CategoryChip label="All" active={category === ''} onClick={() => setParam('category', '')} />
          {CATEGORIES.map((c) => (
            <CategoryChip
              key={c.key}
              icon={c.icon}
              label={c.label}
              active={category === c.key}
              onClick={() => setParam('category', c.key)}
            />
          ))}
        </div>
      </div>

      {/* ── Results ──────────────────────────────────────────────────────── */}
      {loading ? (
        <>
          <span className="sr-only" role="status" aria-live="polite">
            Loading catalogue…
          </span>
          <div className={GRID} aria-hidden="true">
            {Array.from({ length: 10 }).map((_, i) => (
              <CardSkeleton key={i} />
            ))}
          </div>
        </>
      ) : error ? (
        <ErrorState message={error} onRetry={() => void load(page)} />
      ) : products.length === 0 ? (
        <EmptyState
          title={hasActiveFilter ? 'Nothing matches your filters' : 'No products published yet'}
          description={
            hasActiveFilter
              ? 'Try a different keyword or category to see more gifts.'
              : 'Our makers are hard at work. Check back soon for new customisable gifts.'
          }
          action={
            <Button variant="outline" onClick={hasActiveFilter ? clearFilters : () => void load(1)}>
              {hasActiveFilter ? 'Clear filters' : 'Refresh'}
            </Button>
          }
        />
      ) : (
        <>
          <Motion variants={staggerContainer} initial="hidden" animate="visible" className={GRID}>
            {products.map((p) => (
              <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
            ))}
          </Motion>

          {lastPage > 1 && (
            <nav className="flex items-center justify-center gap-4" aria-label="Pagination">
              <Button
                variant="outline"
                size="sm"
                disabled={loading || page <= 1}
                onClick={() => void load(page - 1)}
              >
                Previous
              </Button>
              <span className="text-sm text-fg-muted">
                Page {page} of {lastPage}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={loading || page >= lastPage}
                onClick={() => void load(page + 1)}
              >
                Next
              </Button>
            </nav>
          )}
        </>
      )}
    </div>
  );
}

function CategoryChip({
  label,
  icon,
  active,
  onClick,
}: {
  label: string;
  icon?: string;
  active: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        'flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors duration-fast',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg',
        active
          ? 'border-primary bg-primary text-primary-fg'
          : 'border-border bg-surface text-fg-muted hover:border-primary/50 hover:text-fg',
      )}
    >
      {icon && <span aria-hidden="true">{icon}</span>}
      {label}
    </button>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/pages/CataloguePage.test.tsx` — expected: all 4 pass.
Note: the old test `'renders published products from the API'` still passes because the API mock shape is unchanged and search is now server-side (initial load fires once with no debounce when `q` is empty).
Then `npm run typecheck` and full `npm run test` — expected green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/CataloguePage.tsx frontend/src/pages/CataloguePage.test.tsx
git commit -m "feat(frontend): dense marketplace catalogue with category rail, sort and server-side search"
```

---

### Task 7: Frontend — SiteHeader categories dropdown

**Files:**
- Modify: `frontend/src/components/SiteHeader.tsx`
- Test: `frontend/src/components/SiteHeader.test.tsx`

The header stops advertising print classes. Desktop nav = Products + a "Categories" dropdown (2-column panel, all 8 categories with icon + blurb). Mobile drawer lists all categories as `?category=` links.

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/components/SiteHeader.test.tsx`:

```tsx
it('opens the categories menu with marketplace category links', async () => {
  const user = userEvent.setup();
  renderHeader();

  await user.click(screen.getByRole('button', { name: /categories/i }));

  const link = screen.getByRole('link', { name: /drinkware/i });
  expect(link).toHaveAttribute('href', '/products?category=drinkware');
  expect(screen.getByRole('link', { name: /toys & figurines/i })).toBeInTheDocument();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/components/SiteHeader.test.tsx`
Expected: FAIL — no button named "Categories".

- [ ] **Step 3: Implement**

In `frontend/src/components/SiteHeader.tsx`:

1. Replace the desktop nav block (the `<nav … aria-label="Primary">` element — originally lines 58-67, now also containing the Task 0 staff links). The `CATEGORIES.map` loop is replaced by `<CategoriesMenu />`; the Task 0 staff block is KEPT:

```tsx
        <nav className="hidden flex-1 items-center gap-1 md:flex" aria-label="Primary">
          <NavLink to="/products" end className={navLinkClass}>
            Products
          </NavLink>
          <CategoriesMenu />
          {isStaffRole(user?.role) && (
            <>
              <span className="mx-1 h-5 w-px bg-border" aria-hidden="true" />
              <NavLink to="/catalogue-admin" className={navLinkClass}>
                Catalogue Gate
              </NavLink>
              <NavLink to="/production-queue" className={navLinkClass}>
                Production
              </NavLink>
              <NavLink to="/procurement" className={navLinkClass}>
                Procurement
              </NavLink>
            </>
          )}
        </nav>
```

2. Add the `CategoriesMenu` component after the `SiteHeader` function (before `ThemeToggle`):

```tsx
function CategoriesMenu() {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  // Close on any click outside the menu (standard disclosure pattern).
  useEffect(() => {
    if (!open) return;
    const onDocClick = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, [open]);

  return (
    <div
      ref={ref}
      className="relative"
      onKeyDown={(e) => {
        if (e.key === 'Escape') setOpen(false);
      }}
    >
      <button
        type="button"
        aria-expanded={open}
        aria-haspopup="true"
        onClick={() => setOpen((o) => !o)}
        className={cn(
          'rounded-md px-3 py-2 text-sm font-medium transition-colors duration-fast',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          open ? 'bg-surface-2 text-fg' : 'text-fg-muted hover:bg-surface-2 hover:text-fg',
        )}
      >
        Categories <span aria-hidden="true">▾</span>
      </button>
      {open && (
        <div className="absolute left-0 top-full z-modal mt-1 grid w-[28rem] grid-cols-2 gap-1 rounded-lg border border-border bg-surface p-2 shadow-lg">
          {CATEGORIES.map((c) => (
            <Link
              key={c.key}
              to={`/products?category=${c.key}`}
              onClick={() => setOpen(false)}
              className="flex items-start gap-2.5 rounded-md px-3 py-2 hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <span aria-hidden="true" className="text-lg leading-6">
                {c.icon}
              </span>
              <span>
                <span className="block text-sm font-medium text-fg">{c.label}</span>
                <span className="block text-xs text-fg-muted">{c.blurb}</span>
              </span>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
```

3. In the mobile drawer, replace the category `NavLink` loop (lines 279-283):

```tsx
            {CATEGORIES.map((c) => (
              <NavLink
                key={c.key}
                to={`/products?category=${c.key}`}
                onClick={onClose}
                className={navLinkClass}
              >
                <span aria-hidden="true">{c.icon}</span> {c.label}
              </NavLink>
            ))}
```

(Only the `to` prop changes: `?class=` → `?category=`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/components/SiteHeader.test.tsx` — all 3 pass. Then `npm run typecheck`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/SiteHeader.tsx frontend/src/components/SiteHeader.test.tsx
git commit -m "feat(frontend): marketplace categories dropdown in the site header"
```

---

### Task 8: Frontend — HomePage rework (marketplace landing)

**Files:**
- Modify: `frontend/src/pages/HomePage.tsx` (full rewrite)
- Test: `frontend/src/pages/HomePage.test.tsx`

Page identity: a storefront window, not a pitch. Compact hero with a working search box (unique feature: search-first landing), 8 category tiles, a horizontally snap-scrolling **New arrivals** rail (unique feature), and a dense **Popular right now** grid. The "How it works" steps and trust/benefit bars are deleted per spec — no explainers.

- [ ] **Step 1: Write the failing test**

Replace the content of `frontend/src/pages/HomePage.test.tsx` with:

```tsx
import { expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import HomePage from './HomePage';
import * as catalogue from '../lib/catalogue';

vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue({
  data: [
    {
      id: 5,
      name: 'A5 Notebook',
      class: 'CORE',
      category: 'stationery',
      from_price: 7.58,
      currency: 'SGD',
      is_printable: true,
    } as any,
  ],
  meta: { current_page: 1, last_page: 1, total: 1 },
} as any);

it('renders search hero, category tiles, new arrivals and popular rails — no explainer sections', async () => {
  render(
    <ThemeProvider>
      <MemoryRouter>
        <HomePage />
      </MemoryRouter>
    </ThemeProvider>,
  );

  expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
  expect(screen.getByRole('search')).toBeInTheDocument();
  expect(screen.getByText(/shop by category/i)).toBeInTheDocument();
  // Drinkware appears twice (hero quick-link + category tile) — assert all point at the category URL.
  const drinkwareLinks = screen.getAllByRole('link', { name: /drinkware/i });
  expect(drinkwareLinks.length).toBeGreaterThanOrEqual(2);
  drinkwareLinks.forEach((l) => expect(l).toHaveAttribute('href', '/products?category=drinkware'));
  expect(screen.getByText(/new arrivals/i)).toBeInTheDocument();
  // The product appears in both rails.
  await waitFor(() => expect(screen.getAllByText(/A5 Notebook/).length).toBeGreaterThanOrEqual(2));
  // Marketplace, not a pitch page: explainers must be gone.
  expect(screen.queryByText(/how it works/i)).not.toBeInTheDocument();
  expect(screen.queryByText(/3-day turnaround/i)).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/pages/HomePage.test.tsx`
Expected: FAIL — no `role="search"`, category link href is `?class=…`, "New arrivals" missing, "How it works" present.

- [ ] **Step 3: Rewrite the page**

Replace the entire content of `frontend/src/pages/HomePage.tsx` with:

```tsx
import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button, EmptyState, Input } from '../ui';
import { ErrorState } from '../components/ui/States';
import { CardSkeleton, ProductCard } from '../components/product/ProductCard';
import { Motion, fadeInUp, staggerContainer } from '../motion';
import { fetchCatalogue, productPath } from '../lib/catalogue';
import { CATEGORIES } from '../lib/categories';
import type { Product } from '../types';

const MAX_POPULAR = 10;
const MAX_NEW = 8;

export default function HomePage() {
  const navigate = useNavigate();
  const [popular, setPopular] = useState<Product[]>([]);
  const [fresh, setFresh] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = async (isActive: () => boolean = () => true) => {
    setLoading(true);
    setError(null);
    try {
      const [pop, latest] = await Promise.all([
        fetchCatalogue({}),
        fetchCatalogue({ sort: 'newest' }),
      ]);
      if (!isActive()) return;
      setPopular(pop.data.slice(0, MAX_POPULAR));
      setFresh(latest.data.slice(0, MAX_NEW));
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
  }, []);

  const onSearch = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const value = new FormData(e.currentTarget).get('q')?.toString().trim() ?? '';
    navigate(value ? `/products?q=${encodeURIComponent(value)}` : '/products');
  };

  return (
    <div className="flex flex-col gap-8 sm:gap-10">
      {/* ── Compact search-first hero ─────────────────────────────────────── */}
      <Motion
        variants={fadeInUp}
        initial="hidden"
        animate="visible"
        className="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-brand-50 via-surface to-accent-50 px-6 py-8 sm:px-10 sm:py-10"
      >
        <div
          className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-brand-100/50 blur-3xl"
          aria-hidden="true"
        />
        <div className="relative max-w-2xl">
          <h1 className="font-display text-3xl font-bold leading-tight text-fg sm:text-4xl">
            The marketplace for personalised gifts.
          </h1>
          <form onSubmit={onSearch} role="search" className="mt-4 flex max-w-xl gap-2">
            <div className="flex-1">
              <Input
                name="q"
                type="search"
                aria-label="Search gifts"
                placeholder="Search mugs, totes, figurines…"
              />
            </div>
            <Button type="submit" variant="primary" size="md">
              Search
            </Button>
          </form>
          <div className="mt-3 flex flex-wrap gap-1.5 text-sm">
            <span className="text-fg-subtle">Popular:</span>
            {CATEGORIES.slice(0, 4).map((c) => (
              <Link
                key={c.key}
                to={`/products?category=${c.key}`}
                className="text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                {c.label}
              </Link>
            ))}
          </div>
        </div>
      </Motion>

      {/* ── Shop by category — 8 marketplace tiles ────────────────────────── */}
      <section aria-labelledby="home-categories">
        <h2 id="home-categories" className="font-display text-xl text-fg sm:text-2xl">
          Shop by category
        </h2>
        <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {CATEGORIES.map((c) => (
            <Link
              key={c.key}
              to={`/products?category=${c.key}`}
              className="group flex items-center gap-3 rounded-xl border border-border bg-surface p-4 shadow-card transition-all duration-base ease-standard hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg motion-reduce:hover:translate-y-0"
            >
              <span className="text-3xl" aria-hidden="true">
                {c.icon}
              </span>
              <span>
                <span className="block font-display text-sm text-fg transition-colors duration-fast group-hover:text-primary sm:text-base">
                  {c.label}
                </span>
                <span className="hidden text-xs text-fg-muted sm:block">{c.blurb}</span>
              </span>
            </Link>
          ))}
        </div>
      </section>

      {/* ── New arrivals — horizontal snap rail ───────────────────────────── */}
      <section aria-labelledby="home-new">
        <div className="flex items-end justify-between gap-4">
          <h2 id="home-new" className="font-display text-xl text-fg sm:text-2xl">
            New arrivals
          </h2>
          <Link
            to="/products?sort=newest"
            className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
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
          ) : fresh.length > 0 ? (
            <div className="-mx-4 flex snap-x gap-4 overflow-x-auto px-4 pb-2">
              {fresh.map((p) => (
                <div key={p.id} className="w-52 shrink-0 snap-start">
                  <ProductCard product={p} to={productPath(p)} showMeta />
                </div>
              ))}
            </div>
          ) : null}
        </div>
      </section>

      {/* ── Popular right now — dense grid ────────────────────────────────── */}
      <section aria-labelledby="home-popular">
        <div className="flex items-end justify-between gap-4">
          <h2 id="home-popular" className="font-display text-xl text-fg sm:text-2xl">
            Popular right now
          </h2>
          <Link
            to="/products"
            className="text-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            View all
          </Link>
        </div>
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
          ) : popular.length === 0 ? (
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
            <Motion
              variants={staggerContainer}
              initial="hidden"
              animate="visible"
              className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5"
            >
              {popular.map((p) => (
                <ProductCard key={p.id} product={p} to={productPath(p)} showMeta />
              ))}
            </Motion>
          )}
        </div>
      </section>
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/pages/HomePage.test.tsx` — pass. Then `npm run typecheck` + full `npm run test`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/HomePage.tsx frontend/src/pages/HomePage.test.tsx
git commit -m "feat(frontend): marketplace home with search hero, category tiles and product rails"
```

---

### Task 9: Frontend — ProductDetailPage (category breadcrumb + live name preview)

**Files:**
- Modify: `frontend/src/pages/ProductDetailPage.tsx`
- Test: `frontend/src/pages/ProductDetailPage.test.tsx`

Unique feature: **"See your name on it"** — an inline input that overlays the typed text live on the product photo, then hands it to the designer via `?name=`. Print-method *chooser* is removed from public UI (print method remains as a spec row only); breadcrumb and specs use the marketplace category.

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/pages/ProductDetailPage.test.tsx` (also add `import userEvent from '@testing-library/user-event';` at the top, and add `category: 'stationery',` to the `fetchProduct` mock object after `class: 'CORE',`):

```tsx
it('overlays a live name preview and carries it into the designer link', async () => {
  const user = userEvent.setup();
  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/products/5']}>
        <Routes>
          <Route path="/products/:id" element={<ProductDetailPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
  await waitFor(() =>
    expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument(),
  );

  await user.type(screen.getByLabelText(/see your name on it/i), 'Ada');

  expect(screen.getByTestId('name-preview-overlay')).toHaveTextContent('Ada');
  expect(screen.getByRole('link', { name: /customize in studio/i })).toHaveAttribute(
    'href',
    '/design/5?name=Ada',
  );
});

it('uses the marketplace category for breadcrumb, not the print class', async () => {
  render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/products/5']}>
        <Routes>
          <Route path="/products/:id" element={<ProductDetailPage />} />
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
  await waitFor(() =>
    expect(screen.getByRole('heading', { name: /A5 Hardcover Notebook/i })).toBeInTheDocument(),
  );

  const crumb = screen.getByRole('navigation', { name: /breadcrumb/i });
  expect(crumb).toHaveTextContent('Stationery & Office');
  const catLink = screen.getByRole('link', { name: /stationery & office/i });
  expect(catLink).toHaveAttribute('href', '/products?category=stationery');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `npx vitest run src/pages/ProductDetailPage.test.tsx`
Expected: the two new tests FAIL (no such input/testid; breadcrumb shows the class fallback and isn't a link).

- [ ] **Step 3: Implement**

All edits in `frontend/src/pages/ProductDetailPage.tsx`:

1. Add `Input` to the `../ui` import list.
2. Add state next to the other `useState` calls (after `selectedTierQty`, ~line 81):

```tsx
  const [previewName, setPreviewName] = useState('');
```

3. Delete the `selectedPrintMethod` state (line 80) and the `setSelectedPrintMethod(p.print_method ?? null);` line in the product-load effect (~line 99). Keep `PRINT_METHOD_LABELS` (still used by the specs table).

4. Root spacing: change the top-level `<div className="flex flex-col gap-16">` to `gap-10`.

5. In the gallery block, inside the `<div className="group relative aspect-[4/3] …">` that wraps `<CardImage product={product} />`, add the live overlay directly after `<CardImage product={product} />`:

```tsx
              {previewName && (
                <span
                  data-testid="name-preview-overlay"
                  aria-hidden="true"
                  className="pointer-events-none absolute bottom-6 left-1/2 -translate-x-1/2 rounded bg-black/35 px-3 py-1 font-display text-2xl text-white drop-shadow-md backdrop-blur-[2px]"
                >
                  {previewName}
                </span>
              )}
```

6. Breadcrumb: replace `<li>{categoryLabel(product.class)}</li>` (~line 274) with:

```tsx
                <li>
                  <Link
                    to={`/products?category=${product.category ?? ''}`}
                    className="hover:text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-bg"
                  >
                    {categoryLabel(product.category)}
                  </Link>
                </li>
```

7. Delete the whole "Print method (presentational)" `<Motion>` section (the `{(product.print_method || product.class !== 'CORE') && (…)}` block, ~lines 337-366).

8. In its place, add the name-preview widget:

```tsx
          {/* Live personalization teaser — the marketplace hook: type a name,
              see it on the product, then carry it into the studio. */}
          <Motion variants={staggerItem} className="flex flex-col gap-2 rounded-xl border border-brand-100 bg-brand-50/50 p-4">
            <Input
              label="See your name on it"
              placeholder="Type a name — watch the photo"
              value={previewName}
              maxLength={24}
              onChange={(e) => setPreviewName(e.target.value)}
            />
            <p className="text-xs text-fg-muted">
              Your text appears on the product photo instantly and comes with you into the studio.
            </p>
          </Motion>
```

9. Update the studio CTA (~line 408) to carry the name:

```tsx
            <LinkButton
              to={`${designPath(product)}${previewName ? `?name=${encodeURIComponent(previewName)}` : ''}`}
              variant="primary"
              size="lg"
              className="w-full sm:w-auto"
            >
              Customize in studio
            </LinkButton>
```

10. Specs table: change `<SpecRow label="Category" value={categoryLabel(product.class)} />` to `<SpecRow label="Category" value={categoryLabel(product.category)} />`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `npx vitest run src/pages/ProductDetailPage.test.tsx` — all 3 pass. Then `npm run typecheck` (confirms the removed `selectedPrintMethod` left no dangling references) and full `npm run test`.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/ProductDetailPage.tsx frontend/src/pages/ProductDetailPage.test.tsx
git commit -m "feat(frontend): live name preview on product page, category breadcrumb"
```

---

### Task 10: Frontend — Designer handoff (?name= prefill) + qty & live price bar

**Files:**
- Modify: `frontend/src/components/DesignerCanvas.tsx:16-48,133-148`
- Modify: `frontend/src/pages/ProductDesignerPage.tsx`
- Modify: `frontend/src/stores/cartStore.ts:15,33-36`
- Test: `frontend/src/stores/cartStore.test.ts`

Unique designer features: the name typed on the PDP is pre-seeded onto the canvas, and a sticky bar shows a **live unit-price estimate for a chosen quantity** (re-quotes when qty, variant or artwork changes), with that qty flowing into the cart line.

- [ ] **Step 1: Write the failing store test**

Append to `frontend/src/stores/cartStore.test.ts` (inside the existing describe if there is one, otherwise top level; reuse the file's existing product fixture if one exists, else this standalone literal):

```ts
it('addLine stores the requested quantity (default 1)', () => {
  useCartStore.setState({ lines: [] });
  const product = { id: 9, name: 'Mug', from_price: 5, currency: 'SGD' } as any;

  useCartStore.getState().addLine(product, null, {}, 50);
  useCartStore.getState().addLine(product, null, {});

  const lines = useCartStore.getState().lines;
  expect(lines[0].qty).toBe(50);
  expect(lines[1].qty).toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/stores/cartStore.test.ts`
Expected: FAIL — `addLine` takes 3 args and hardcodes `qty: 1` (TS error or qty === 1).

- [ ] **Step 3: Extend the cart store**

In `frontend/src/stores/cartStore.ts`:

- Interface (line 15): `addLine: (product: Product, variant: Variant | null, customization: Customization, qty?: number) => void;`
- Implementation (lines 33-36):

```ts
      addLine: (product, variant, customization, qty = 1) => {
        const key = `${product.id}:${variant?.id ?? 0}:${Date.now()}`;
        const safeQty = Number.isFinite(qty) ? Math.max(1, Math.floor(qty)) : 1;
        set((s) => ({ lines: [...s.lines, { key, product, variant, qty: safeQty, customization }] }));
      },
```

Run: `npx vitest run src/stores/cartStore.test.ts` — expected PASS.

- [ ] **Step 4: DesignerCanvas — accept an initial name and auto-place it**

In `frontend/src/components/DesignerCanvas.tsx`:

1. Add to `DesignerCanvasProps` (after `onCapture`):

```ts
  /** Pre-seeded from the PDP "see your name on it" teaser (?name=…). */
  initialNameText?: string;
```

2. Update the function signature:

```tsx
export default function DesignerCanvas({ width = 500, height = 380, backgroundUrl, onCapture, initialNameText }: DesignerCanvasProps) {
```

3. Seed the state (line 43): `const [nameText, setNameText] = useState(initialNameText ?? '');`

4. Add a one-shot auto-apply effect directly after the `useEffect` that creates the fabric canvas (after line 101). It must run once the canvas is ready:

```tsx
  // Auto-place the name carried over from the product page — once, after the
  // canvas is live, so the buyer lands with their personalization already on.
  const seededRef = useRef(false);
  useEffect(() => {
    if (!ready || seededRef.current || !initialNameText) return;
    seededRef.current = true;
    applyNameText();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ready]);
```

(`applyNameText` already exists at line 133 and reads `nameText`, which was seeded in step 3.)

- [ ] **Step 5: ProductDesignerPage — read ?name=, add qty + live estimate to the sticky bar**

In `frontend/src/pages/ProductDesignerPage.tsx`:

1. Update imports:
   - `import { useNavigate, useParams, useSearchParams } from 'react-router-dom';`
   - Add `PriceEstimate` to the types import: `import type { PriceEstimate, Product, Variant } from '../types';`
2. Inside the component, read the param (after `const navigate = useNavigate();`):

```tsx
  const [searchParams] = useSearchParams();
  const initialName = searchParams.get('name')?.slice(0, 24) ?? '';
```

3. Add qty/estimate state (after the `model3dOptions` state):

```tsx
  const QTY_OPTIONS = [1, 25, 50, 100, 250, 500];
  const [qty, setQty] = useState(50);
  const [estimate, setEstimate] = useState<{ unit: number; total: number; currency: string } | null>(null);
```

(Move `const QTY_OPTIONS` above the component as a module constant — it must not be re-created per render inside hooks deps.)

4. Add the live re-quote effect (after the `load` effect):

```tsx
  // Live quote: re-estimate whenever qty, variant or captured artwork changes.
  // Event-driven single POST per change — never polled.
  useEffect(() => {
    if (!product) return;
    let active = true;
    api
      .post<PriceEstimate>('/price-estimate', {
        line_items: [
          { product_id: product.id, variant_id: variantId, qty, has_customization: !!artwork },
        ],
      })
      .then(({ data }) => {
        if (!active) return;
        setEstimate({ unit: data.lines[0]?.unit_price ?? 0, total: data.total, currency: data.currency });
      })
      .catch(() => {
        if (active) setEstimate(null);
      });
    return () => {
      active = false;
    };
  }, [product, variantId, qty, artwork]);
```

5. Pass the name into the canvas (line 147):

```tsx
          <DesignerCanvas backgroundUrl={product.image_url} onCapture={handleCapture} initialNameText={initialName} />
```

6. Pass qty into the cart (in `addToCart`): `addLine(product, selectedVariant, customization, qty);`

7. Rework the sticky action bar (lines 149-176) to include the qty picker + live price:

```tsx
          {/* Sticky action bar — qty picker + live unit price + add to cart */}
          <div className="sticky bottom-4 z-raised">
            <div className="flex flex-col gap-3 rounded-lg border border-border bg-surface/95 p-4 shadow-lg backdrop-blur sm:flex-row sm:items-center sm:justify-between">
              <div className="flex flex-wrap items-center gap-3 text-sm">
                {artwork ? (
                  <Badge tone="success" dot size="md">
                    Design captured
                  </Badge>
                ) : (
                  <span className="text-fg-muted">
                    {is3d
                      ? 'Pick a colour, place your design, then choose “Use this design” — or add to cart plain.'
                      : 'Add a logo or text, then choose “Use this design”.'}
                  </span>
                )}
              </div>
              <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:gap-3">
                <div className="w-28">
                  <Select label="Quantity" value={qty} onChange={(e) => setQty(Number(e.target.value))}>
                    {QTY_OPTIONS.map((n) => (
                      <option key={n} value={n}>
                        {n} pcs
                      </option>
                    ))}
                  </Select>
                </div>
                {estimate && (
                  <p className="text-sm text-fg-muted" role="status" aria-live="polite">
                    <span className="font-semibold text-fg">
                      {estimate.currency} {estimate.unit.toFixed(2)}
                    </span>{' '}
                    / unit ·{' '}
                    <span className="font-semibold text-fg">
                      {estimate.currency} {estimate.total.toFixed(2)}
                    </span>{' '}
                    for {qty}
                  </p>
                )}
                {uploadError && (
                  <p className="text-sm text-danger" role="alert">
                    {uploadError}
                  </p>
                )}
                <Button onClick={addToCart} disabled={!product || uploading} loading={uploading} size="lg">
                  {uploading ? 'Uploading…' : 'Add to cart'}
                </Button>
              </div>
            </div>
          </div>
```

- [ ] **Step 6: Verify**

Run: `npm run typecheck` then full `npm run test` (from `frontend/`).
Expected: all green — no existing test covers ProductDesignerPage directly; cartStore test now passes.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/DesignerCanvas.tsx frontend/src/pages/ProductDesignerPage.tsx frontend/src/stores/cartStore.ts frontend/src/stores/cartStore.test.ts
git commit -m "feat(frontend): designer name prefill from PDP plus live qty pricing bar"
```

---

### Task 11: Full verification sweep

- [ ] **Step 1: Backend suite**

Run from repo root: `php artisan test`
Expected: all green.

- [ ] **Step 2: Frontend suite + types**

Run from `frontend/`: `npm run typecheck` then `npm run test`
Expected: all green.

- [ ] **Step 3: Manual smoke via dev servers**

Start backend (`php artisan serve`) + frontend (`npm run dev` in `frontend/`), then verify in the browser (or preview tools):
- `/` — search hero submits to `/products?q=…`; 8 category tiles link to `/products?category=…`; New-arrivals rail scrolls horizontally; no "How it works"/trust sections.
- `/products?category=drinkware` — chip rail highlights Drinkware; only drinkware items; sort by price reorders; typing in search re-queries server-side (pagination intact).
- Product page — breadcrumb shows the marketplace category; typing in "See your name on it" overlays text on the photo; "Customize in studio" href ends `?name=…`.
- Designer — name pre-placed on canvas; changing quantity updates the live unit price; add to cart carries the qty into `/cart`.
- Header — Categories dropdown lists 8 categories; mobile drawer links use `?category=`.
- Staff flow — log in as `ops@giftlab.local` and `superadmin@giftlab.local`: both land on `/catalogue-admin`; header shows Catalogue Gate / Production / Procurement links (desktop + drawer); `/quotes` shows the Company column with real company names and staff copy; header quotes link reads "Quotes". Buyer login still lands on `/quotes`, sees "My Orders", no ops links, no Company column.

- [ ] **Step 4: Commit any smoke-test fixes, then finish**

Use the `superpowers:finishing-a-development-branch` skill to decide merge/PR.

---

## Self-Review Notes

- **Spec coverage:** marketplace categorization (Tasks 1-4), no print-method taxonomy in public UI (Tasks 5-9: class badge removed from cards, print-method chooser removed from PDP, header class links replaced), no how-it-works/benefit explainers (Task 8 deletes STEPS + TRUST; PDP trust mini-row from Task 9's file is presentational product info and small — if strictness is wanted, deleting the `TRUST` array + mini-row in Task 9 step 3 is a one-line addition), reduced whitespace (gap-16→gap-10/8, p-4→p-3 cards, compact hero, denser 5-col grids), unique per-page features (search-first hero + arrivals rail on Home; chip rail + personalize hover CTA in catalogue; live name preview on PDP; name handoff + live qty pricing in designer).
- **Legacy `?class=` URLs:** backend keeps accepting `class` (untouched), old bookmarked links simply show unfiltered results in the new UI — acceptable, no redirect needed.
- **Type consistency:** `fetchCatalogue(CatalogueQuery)` used identically in Tasks 4, 6, 8; `categoryLabel(string | null | undefined)` matches PDP/card usage; `addLine(..., qty?)` matches Task 10 caller; `initialNameText` prop name consistent between DesignerCanvas and ProductDesignerPage.
- **Placeholder scan:** every code step contains complete code; no TBDs.
