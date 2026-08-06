# Unplug & Hide Legacy Procurement/Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Take the old restock menu off-screen, rename the new buy-list menu, and stop the app seeding filament — all reversible, nothing deleted.

**Architecture:** Two small edits: the staff nav + app routes (frontend), and one line in the root seeder (backend). All underlying models, tables, routes, and logic stay dormant and intact.

**Tech Stack:** React + TypeScript (Vitest), Laravel (Pest).

Spec: `docs/superpowers/specs/2026-08-06-unplug-legacy-procurement-design.md`
Rides on PR #26 (branch `feat/buy-list-and-catalogue-gate`).

---

### Task 1: Rename the buy-list menu and hide the old restock menu

**Files:**
- Modify: `frontend/src/components/StaffLayout.tsx:36-38` (nav items)
- Modify: `frontend/src/App.tsx:38` (lazy import), `:195` (route)
- Test: `frontend/src/components/StaffLayout.test.tsx` (create)

- [ ] **Step 1: Write the failing test**

Create `frontend/src/components/StaffLayout.test.tsx`:

```tsx
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import StaffLayout from './StaffLayout';
import { useAuthStore } from '../stores/authStore';

const initialAuth = useAuthStore.getState();

afterEach(() => {
  cleanup();
  useAuthStore.setState(initialAuth, true);
});

function renderNav() {
  // Superadmin sees every nav item (hasPermission short-circuits true).
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'Root', email: 'root@x.test', role: 'superadmin' },
  } as never);
  return render(
    <ThemeProvider>
      <MemoryRouter initialEntries={['/dashboard']}>
        <Routes>
          <Route element={<StaffLayout />}>
            <Route path="/dashboard" element={<div>home</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </ThemeProvider>,
  );
}

describe('staff nav', () => {
  it('shows a "Buy list" item pointing at /procurement', () => {
    renderNav();
    const links = screen.getAllByRole('link', { name: 'Buy list' });
    expect(links.length).toBeGreaterThan(0);
    expect(links[0]).toHaveAttribute('href', '/procurement');
  });

  it('no longer shows the old restock "Buy-list" (/reorders) item', () => {
    renderNav();
    expect(screen.queryAllByRole('link', { name: 'Buy-list' })).toHaveLength(0);
    expect(screen.queryByText('Procurement')).toBeNull();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/components/StaffLayout.test.tsx`
Expected: FAIL — the nav still labels `/procurement` "Procurement" and still has the "Buy-list" → `/reorders` item.

- [ ] **Step 3: Edit the nav items**

In `frontend/src/components/StaffLayout.tsx`, change lines 37-38 from:

```tsx
    { to: '/procurement', label: 'Procurement', badge: q?.procurementToReconfirm, permission: 'procurement.view' },
    { to: '/reorders', label: 'Buy-list', badge: q?.reordersOpen, permission: 'reorders.view' },
```

to (rename to "Buy list", drop the stale badge, remove the `/reorders` line entirely):

```tsx
    { to: '/procurement', label: 'Buy list', permission: 'procurement.view' },
```

- [ ] **Step 4: Remove the old route + import**

In `frontend/src/App.tsx`, delete the lazy import at line 38:

```tsx
const ReorderBuyListPage = lazy(() => import('./pages/ReorderBuyListPage'));
```

and delete the route at line 195:

```tsx
              <Route path="reorders" element={<ProtectedRoute permission="reorders.view"><ReorderBuyListPage /></ProtectedRoute>} />
```

Leave `frontend/src/pages/ReorderBuyListPage.tsx` on disk (dormant, unreferenced).

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd frontend && npx vitest run src/components/StaffLayout.test.tsx`
Expected: PASS (both tests).

- [ ] **Step 6: Typecheck**

Run: `cd frontend && npx tsc --noEmit`
Expected: EXIT 0. If it flags `ReorderBuyListPage` as unused elsewhere, confirm no other file imports it (`grep -rn ReorderBuyListPage frontend/src`) — only its own file should remain.

- [ ] **Step 7: Full frontend suite (catch nav-dependent tests)**

Run: `cd frontend && npx vitest run`
Expected: PASS. If a test asserted the old "Procurement"/"Buy-list" labels or visited `/reorders`, update it to the new labels / remove the `/reorders` navigation.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/components/StaffLayout.tsx frontend/src/App.tsx frontend/src/components/StaffLayout.test.tsx
git commit -m "feat(procurement): rename menu to Buy list, hide old restock menu"
```

---

### Task 2: Stop seeding filament stock

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php:18-26` (root seeder run list)
- Test: `tests/Feature/DatabaseSeederNoFilamentTest.php` (create)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DatabaseSeederNoFilamentTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Filament;
use Illuminate\Support\Facades\Artisan;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('does not seed any filament stock via the root seeder', function (): void {
    Artisan::call('db:seed', ['--force' => true]);

    expect(Filament::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/DatabaseSeederNoFilamentTest.php`
Expected: FAIL — `FilamentSeeder` seeds rows, so the count is > 0.

- [ ] **Step 3: Remove FilamentSeeder from the root seeder**

In `database/seeders/DatabaseSeeder.php`, remove the `FilamentSeeder::class,` line from the `$this->call([...])` array (line 24). Update the class docblock so it no longer says "then starter filament stock". Leave `database/seeders/FilamentSeeder.php` on disk (dormant — tests that need filament call it or the factory directly).

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/DatabaseSeederNoFilamentTest.php`
Expected: PASS.

- [ ] **Step 5: Regression — seeder-dependent tests**

Run: `php artisan test --filter=Seeder`
Expected: PASS. If any test asserted the root seeder produces filament, update it (the behaviour is intentionally removed).

- [ ] **Step 6: Commit**

```bash
git add database/seeders/DatabaseSeeder.php tests/Feature/DatabaseSeederNoFilamentTest.php
git commit -m "feat(procurement): stop seeding filament stock (tracked off-app)"
```

---

## Self-Review Notes

- **Spec coverage:** hide restock menu (Task 1, steps 3-4), rename new menu + drop stale badge (Task 1, step 3), stop stocking filament (Task 2). All three spec changes covered.
- **Left dormant, verified not touched:** `ReorderBuyListPage.tsx`, `AdminReorderController`, `SupplierReorder`, `/admin/supplier-reorders` routes, `FilamentSeeder`, `Filament` model/table, `ProcurementManager` + strategies, `procure/confirmStock/reconfirm` routes, `PaymentService` auto-procure, refund-on-cancel — none removed.
- **Type consistency:** nav label "Buy list" (with a space) matches the page `<h1>Buy list</h1>` and the test queries; the old label was "Buy-list" (hyphen) — the test asserts the hyphenated one is gone.
- **Risk:** low. If `AdminReorderTest`/`BuyListSourceLinksTest` (backend, supplier-reorder) still pass — they should, backend untouched — no backend behaviour changed beyond the one seeder line.
