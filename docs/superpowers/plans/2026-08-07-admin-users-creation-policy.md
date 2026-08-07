# Users — Creation Policy (Piece A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admins can create only `staff_admin` and `superadmin` accounts; buyer creation is removed everywhere (buyers self-register). Buyers remain visible/deactivatable in the list.

**Architecture:** Enforce server-side in `AdminUserController::store` (the real gate), then align the create form. One existing Pest test asserts the old behaviour and must be rewritten.

**Tech Stack:** Laravel 12 + Pest (backend), React 18 + Vitest + Testing Library (frontend).

Spec: `docs/superpowers/specs/2026-08-07-admin-dashboard-users-ux-design.md` (Piece A).

---

## File Structure

- Modify: `app/Http/Controllers/AdminUserController.php` — `store()` validation + body.
- Modify: `tests/Feature/AdminUserManagementTest.php` — rewrite the buyer-create test; keep staff-create test.
- Modify: `frontend/src/pages/UserAdminCreatePage.tsx` — role options, drop company field + copy.
- Create: `frontend/src/pages/UserAdminCreatePage.test.tsx` — asserts only staff roles, no company field.

---

## Task 1: Backend — reject buyer creation

**Files:**
- Modify: `app/Http/Controllers/AdminUserController.php:104-131`
- Test: `tests/Feature/AdminUserManagementTest.php:83-103`

- [ ] **Step 1: Rewrite the existing buyer test to assert rejection**

Replace the whole `it('requires company_id when creating a buyer, and creates it when provided', …)` block (currently at `tests/Feature/AdminUserManagementTest.php:83`) with:

```php
it('rejects creating a buyer account (buyers self-register)', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->postJson('/api/admin/users', [
        'name' => 'Nope Buyer',
        'email' => 'nope.buyer@example.com',
        'password' => 'password123',
        'role' => 'buyer',
        'company_id' => $this->company->id,
    ])->assertStatus(422)->assertJsonValidationErrors('role');

    expect(User::where('email', 'nope.buyer@example.com')->exists())->toBeFalse();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest --filter="rejects creating a buyer account"`
Expected: FAIL — the endpoint currently returns 201 (buyer created), so the 422 assertion fails.

- [ ] **Step 3: Restrict the role rule and drop the buyer/company branch**

In `app/Http/Controllers/AdminUserController.php::store`, change the validation and body so buyer is not a valid role and no company handling remains. Replace lines `104-131` with:

```php
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            // Buyers self-register (they create their company at /register); staff
            // never provision them. Only staff-tier accounts are creatable here.
            'role' => ['required', 'string', Rule::in(['staff_admin', 'superadmin'])],
        ]);

        // Only a superadmin may mint another superadmin - a delegated Users
        // manager must not be able to create an account above their own level.
        if ($validated['role'] === 'superadmin' && ! $request->user()->isSuperadmin()) {
            return response()->json(['message' => 'Only a superadmin can create a superadmin.'], 422);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            // Staff/superadmin are always company-less; buyers are not creatable here.
            'company_id' => null,
        ]);
```

Leave the `forceFill(registration_source)`, `audit->log`, and `return` lines (currently `133-137`) unchanged.

- [ ] **Step 4: Run the rewritten test + the staff-create test**

Run: `vendor/bin/pest --filter="rejects creating a buyer account"`
Expected: PASS.

Run: `vendor/bin/pest --filter="creates a staff user with company_id forced null"`
Expected: PASS (unchanged behaviour).

- [ ] **Step 5: Run the whole admin-user suite to catch fallout**

Run: `vendor/bin/pest tests/Feature/AdminUserManagementTest.php`
Expected: PASS. (If any other test posted `role=buyer` expecting success, update it to a staff role — none currently do besides the one rewritten in Step 1.)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminUserController.php tests/Feature/AdminUserManagementTest.php
git commit -m "feat(admin): forbid creating buyer accounts via admin API"
```

---

## Task 2: Frontend — create form offers only staff roles

**Files:**
- Modify: `frontend/src/pages/UserAdminCreatePage.tsx`
- Test: `frontend/src/pages/UserAdminCreatePage.test.tsx` (create)

- [ ] **Step 1: Write the failing test**

Create `frontend/src/pages/UserAdminCreatePage.test.tsx`:

```tsx
import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../lib/api', () => ({
  default: { get: vi.fn().mockResolvedValue({ data: { data: [] } }), post: vi.fn() },
  apiError: (e: unknown) => String(e),
  ensureCsrf: vi.fn().mockResolvedValue(undefined),
}));

import { ThemeProvider, ToastProvider } from '../ui';
import UserAdminCreatePage from './UserAdminCreatePage';

afterEach(cleanup);

function renderPage() {
  return render(
    <ThemeProvider>
      <ToastProvider>
        <MemoryRouter>
          <UserAdminCreatePage />
        </MemoryRouter>
      </ToastProvider>
    </ThemeProvider>,
  );
}

it('offers only staff roles and no buyer/company field', () => {
  renderPage();
  const roleSelect = screen.getByLabelText('Role') as HTMLSelectElement;
  const values = Array.from(roleSelect.options).map((o) => o.value);
  expect(values).toEqual(['staff_admin', 'superadmin']);
  expect(screen.queryByText('Buyer')).toBeNull();
  // Company selector only ever appeared for buyers - it must be gone.
  expect(screen.queryByLabelText('Company')).toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/UserAdminCreatePage.test.tsx`
Expected: FAIL — role options currently include `buyer` (3 values), so the `toEqual(['staff_admin','superadmin'])` assertion fails.

- [ ] **Step 3: Remove buyer + company from the page**

Edit `frontend/src/pages/UserAdminCreatePage.tsx`:

a) Change the default role (line 19):

```tsx
  const [role, setRole] = useState<UserRole>('staff_admin');
```

b) Remove the company state and its fetch. Delete line 20 (`const [companyId, setCompanyId] = useState('');`), line 21 (`const [companies, setCompanies] = useState<AdminCompany[]>([]);`), and the entire `useEffect` that loads companies (lines 25-38).

c) Remove the buyer branch in `validate()` — delete these lines (currently 48-50):

```tsx
    if (role === 'buyer' && !companyId) {
      errors.company_id = 'Select a company for a buyer account.';
    }
```

d) Simplify the payload in `submit()` — replace lines 62-63 with:

```tsx
      const payload: Record<string, unknown> = { name, email, password, role };
```

e) Update the subtitle copy (line 81):

```tsx
        <p className="text-sm text-fg-muted">Create a staff admin or superadmin account.</p>
```

f) Replace the Role `<Select>` options and delete the company `<Select>` block (lines 115-134) with just:

```tsx
            <option value="staff_admin">Staff admin</option>
            <option value="superadmin">Superadmin</option>
          </Select>
```

g) Remove the now-unused `AdminCompany` import (line 6) — leave `UserRole`:

```tsx
import type { UserRole } from '../types';
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/UserAdminCreatePage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Typecheck (catch the removed-import / unused-var fallout)**

Run: `cd frontend && npm run typecheck`
Expected: no errors. (If `AdminCompany` or `companies`/`companyId` are still referenced anywhere, remove those references.)

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/UserAdminCreatePage.tsx frontend/src/pages/UserAdminCreatePage.test.tsx
git commit -m "feat(admin): create form offers only staff roles"
```

---

## Self-Review

- **Spec coverage (Piece A):** backend rejects `role=buyer` (Task 1); create form offers only staff roles and drops the company field + copy (Task 2); buyers remain in the list (untouched — index/serialize unchanged). Covered.
- **Placeholders:** none — every step has exact code and commands.
- **Type consistency:** `role` stays `UserRole`; payload no longer sends `company_id`; backend forces `company_id => null`. `AdminCompany` import removed in the same task that removes its uses.
- **Existing-test fallout:** the one test asserting buyer creation is rewritten in Task 1 Step 1; the whole file is run in Step 5 to catch anything else.
