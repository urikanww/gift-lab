# Users — Findability (Piece B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make users findable at scale — broaden search (name/email/company/id), surface role tabs + status toggle, present a scannable sortable table with numbered pagination.

**Architecture:** One backend change (broaden the `q` match in `AdminUserController@index`). The rest is a frontend refactor of `UserAdminPage.tsx`: lift role/status/sort into first-class controls, render a responsive table with clickable sort headers, and numbered pages. The API already whitelists all four `sort` values and returns pagination `meta`, so sort and paging need no backend work.

**Tech Stack:** Laravel 12 + Pest; React 18 + TypeScript + Vitest + Testing Library.

Spec: `docs/superpowers/specs/2026-08-07-admin-dashboard-users-ux-design.md` (Piece B).

## Verified current state

`AdminUserController@index` reads `status` (default `active`), `role`, `company`, `q`, `created_from/to`, `sort` (whitelist: `name_asc|name_desc|created_asc|created_desc`, default `name_asc`), `per_page`, paginates, returns `{ data, meta{current_page,last_page,per_page,total} }`. The `q` clause currently matches only `LOWER(name)` / `LOWER(email)`.

`frontend/src/pages/UserAdminPage.tsx` keeps `filters: FilterValues` (popup) + `q`, maps popup values via `userFiltersToParams` (`src/lib/userListFilters.ts`), and renders a search input, `<ListFilters>` popup (role/status/company/created/sort), a per-page select, a stacked `<ul>` of clickable rows, and Prev/Next pagination.

`AdminUser` type: `{ id, name, email, role, company: {id,name}|null, active, created_at, permissions?, permissions_editable? }`. Badges: `RoleBadge`, `ActiveBadge` from `./adminUserBadges`.

## File Structure

- Modify: `app/Http/Controllers/AdminUserController.php` — broaden `q` in `index()`.
- Test: `tests/Feature/AdminUserManagementTest.php` — add search cases.
- Modify: `frontend/src/lib/userListFilters.ts` — remove role/status/sort fields (they become first-class controls); keep company + created.
- Modify: `frontend/src/pages/UserAdminPage.tsx` — first-class role/status/sort state, tabs + toggle, responsive sortable table, numbered pagination.
- Test: `frontend/src/pages/UserAdminPage.test.tsx` (create) — tabs set role param, sort header flips sort, numbered paging.

---

## Task 1: Backend — broaden search to company + id

**Files:**
- Modify: `app/Http/Controllers/AdminUserController.php` (the `->when($q !== '', …)` clause in `index()`)
- Test: `tests/Feature/AdminUserManagementTest.php`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AdminUserManagementTest.php` (after the existing `filters users by role and by q (email)` test):

```php
it('matches q against company name', function (): void {
    Sanctum::actingAs($this->superadmin);

    $acme = Company::factory()->create(['name' => 'Zenith Widgets Pte Ltd']);
    $target = User::factory()->create(['company_id' => $acme->id, 'role' => 'buyer', 'name' => 'Unrelated Name', 'email' => 'x@other.example']);

    $response = $this->getJson('/api/admin/users?q=zenith')->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($target->id);
});

it('matches q against an exact numeric id', function (): void {
    Sanctum::actingAs($this->superadmin);

    $response = $this->getJson('/api/admin/users?q='.$this->buyer->id)->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    // Assert only inclusion: a numeric q also LIKE-matches name/email substrings,
    // and faker's unique emails can contain digits, so an exact-count assertion
    // would be flaky. Inclusion proves the id branch works.
    expect($ids)->toContain($this->buyer->id);
});
```
(The test file already imports `App\Models\Company` and `App\Models\User`.)

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/pest --filter="matches q against"`
Expected: FAIL — company-name and id matches are not implemented (the company test finds nothing; the id test likely returns 0 rows).

- [ ] **Step 3: Broaden the q clause**

In `AdminUserController@index`, replace the existing `q` clause:

```php
            ->when($q !== '', fn ($qr) => $qr->where(function ($w) use ($q): void {
                $like = '%'.mb_strtolower($q).'%';
                $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like]);
            }))
```

with:

```php
            ->when($q !== '', fn ($qr) => $qr->where(function ($w) use ($q): void {
                $like = '%'.mb_strtolower($q).'%';
                $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhereHas('company', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like]));
                // A purely numeric query also matches an exact user id, so staff can
                // paste an id from a URL/log and jump straight to the account.
                if (ctype_digit($q)) {
                    $w->orWhere('id', (int) $q);
                }
            }))
```

- [ ] **Step 4: Run the new tests + the existing q/role test**

Run: `vendor/bin/pest --filter="matches q against"` → PASS.
Run: `vendor/bin/pest --filter="filters users by role and by q"` → PASS (name/email search unbroken).

- [ ] **Step 5: Run the whole file**

Run: `vendor/bin/pest tests/Feature/AdminUserManagementTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AdminUserController.php tests/Feature/AdminUserManagementTest.php
git commit -m "feat(admin): user search matches company name and exact id"
```

---

## Task 2: Frontend — surfaced filters + sortable table

**Files:**
- Modify: `frontend/src/lib/userListFilters.ts`
- Modify: `frontend/src/pages/UserAdminPage.tsx`
- Test: `frontend/src/pages/UserAdminPage.test.tsx` (create)

- [ ] **Step 1: Trim the popup filter config**

In `frontend/src/lib/userListFilters.ts`, remove the `status`, `role`, and `sort` entries from the array returned by `userFilterFields` (they become first-class controls). Keep only `company` and `created`. The function becomes:

```ts
export function userFilterFields(companies: AdminCompany[]): FilterField[] {
  return [
    {
      key: 'company',
      label: 'Company',
      type: 'select',
      placeholder: 'All companies',
      options: companies.map((c) => ({ value: String(c.id), label: c.name })),
    },
    { key: 'created', label: 'Joined', type: 'daterange' },
  ];
}
```

Leave `userFiltersToParams` unchanged — it only emits keys present in the values object, so with role/status/sort no longer in the popup it simply won't emit them (the page supplies those directly). No other edits to this file.

- [ ] **Step 2: Write the failing test**

Create `frontend/src/pages/UserAdminPage.test.tsx`:

```tsx
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

const get = vi.fn();
vi.mock('../lib/api', () => ({
  default: { get: (...a: unknown[]) => get(...a) },
  apiError: (e: unknown) => String(e),
}));

import { ThemeProvider } from '../ui';
import UserAdminPage from './UserAdminPage';

const USER = {
  id: 7,
  name: 'Dana Buyer',
  email: 'dana@acme.example',
  role: 'buyer',
  company: { id: 3, name: 'Acme Pte Ltd' },
  active: true,
  created_at: '2026-01-02T00:00:00Z',
};

function mockList() {
  get.mockImplementation((url: string) => {
    if (url === '/admin/companies') return Promise.resolve({ data: { data: [] } });
    return Promise.resolve({
      data: { data: [USER], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } },
    });
  });
}

function lastUsersParams() {
  const calls = get.mock.calls.filter((c) => c[0] === '/admin/users');
  return calls[calls.length - 1]?.[1]?.params ?? {};
}

beforeEach(() => {
  get.mockReset();
  mockList();
});
afterEach(cleanup);

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <UserAdminPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('sends role=staff_admin when the Staff admin tab is clicked', async () => {
  renderPage();
  await waitFor(() => expect(screen.getByText('Dana Buyer')).toBeTruthy());
  await userEvent.click(screen.getByRole('button', { name: 'Staff admin' }));
  await waitFor(() => expect(lastUsersParams().role).toBe('staff_admin'));
});

it('flips the sort param when the Name column header is clicked', async () => {
  renderPage();
  await waitFor(() => expect(screen.getByText('Dana Buyer')).toBeTruthy());
  // Default is name_asc; first click on Name flips to name_desc.
  await userEvent.click(screen.getByRole('button', { name: /^Name/ }));
  await waitFor(() => expect(lastUsersParams().sort).toBe('name_desc'));
});
```

- [ ] **Step 3: Run to verify it fails**

Run: `cd frontend && npx vitest run src/pages/UserAdminPage.test.tsx`
Expected: FAIL — no "Staff admin" tab button and no sortable "Name" header button exist yet.

- [ ] **Step 4: Rewrite `UserAdminPage.tsx`**

Replace the entire contents of `frontend/src/pages/UserAdminPage.tsx` with:

```tsx
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { AsyncBoundary } from '../components/ui/States';
import { Button, Card, Input, LinkButton, Select, cn } from '../ui';
import ListFilters, { FilterBadges } from '../components/filters/ListFilters';
import type { FilterValues } from '../components/filters/types';
import { userFilterFields, userFiltersToParams } from '../lib/userListFilters';
import { Motion, fadeInUp } from '../motion';
import type { AdminCompany, AdminUser } from '../types';
import { ActiveBadge, RoleBadge } from './adminUserBadges';

interface Meta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

const PER_PAGE_OPTIONS = [15, 30, 50, 100] as const;

const ROLE_TABS = [
  { value: '', label: 'All' },
  { value: 'buyer', label: 'Buyers' },
  { value: 'staff_admin', label: 'Staff admin' },
  { value: 'superadmin', label: 'Superadmin' },
] as const;

const STATUS_TABS = [
  { value: 'active', label: 'Active' },
  { value: 'deactivated', label: 'Deactivated' },
  { value: 'all', label: 'All' },
] as const;

type SortKey = 'name' | 'created';
type SortDir = 'asc' | 'desc';

function Segmented<T extends string>({
  options,
  value,
  onChange,
  ariaLabel,
}: {
  options: readonly { value: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
  ariaLabel: string;
}) {
  return (
    <div role="group" aria-label={ariaLabel} className="inline-flex flex-wrap gap-1 rounded-lg bg-surface-2 p-1">
      {options.map((o) => (
        <button
          key={o.value}
          type="button"
          aria-pressed={value === o.value}
          onClick={() => onChange(o.value)}
          className={cn(
            'rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            value === o.value ? 'bg-surface text-fg shadow-sm' : 'text-fg-muted hover:text-fg',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

function SortCaret({ active, dir }: { active: boolean; dir: SortDir }) {
  if (!active) return <span aria-hidden="true" className="ml-1 text-fg-subtle">↕</span>;
  return <span aria-hidden="true" className="ml-1 text-fg">{dir === 'asc' ? '↑' : '↓'}</span>;
}

export default function UserAdminPage() {
  const navigate = useNavigate();

  const [users, setUsers] = useState<AdminUser[]>([]);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [companies, setCompanies] = useState<AdminCompany[]>([]);

  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(15);

  // First-class controls (were buried in the popup before).
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('active');
  const [sortKey, setSortKey] = useState<SortKey>('name');
  const [sortDir, setSortDir] = useState<SortDir>('asc');

  // Popup now holds only company + joined-date.
  const [filters, setFilters] = useState<FilterValues>({});
  const [q, setQ] = useState('');
  const [debouncedQ, setDebouncedQ] = useState('');

  const fields = useMemo(() => userFilterFields(companies), [companies]);
  const popupParams = useMemo(() => userFiltersToParams(filters), [filters]);
  const sort = `${sortKey}_${sortDir}`;
  const paramsKey = JSON.stringify({ ...popupParams, role, status, sort });

  useEffect(() => {
    const t = setTimeout(() => setDebouncedQ(q), 300);
    return () => clearTimeout(t);
  }, [q]);

  // Any filter/search/page-size change resets to page 1.
  useEffect(() => {
    setPage(1);
  }, [paramsKey, debouncedQ, perPage]);

  useEffect(() => {
    let cancelled = false;
    api
      .get<{ data: AdminCompany[] }>('/admin/companies')
      .then(({ data }) => {
        if (!cancelled) setCompanies(data.data);
      })
      .catch(() => {
        // Non-critical - the company filter just stays empty.
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const { data } = await api.get<{ data: AdminUser[]; meta: Meta }>('/admin/users', {
        params: {
          page,
          per_page: perPage,
          q: debouncedQ || undefined,
          role: role || undefined,
          status,
          sort,
          ...popupParams,
        },
      });
      setUsers(data.data);
      setMeta(data.meta);
    } catch (err) {
      setError(apiError(err));
    } finally {
      setLoading(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, perPage, debouncedQ, paramsKey]);

  useEffect(() => {
    void load();
  }, [load]);

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortKey(key);
      setSortDir('asc');
    }
  };

  const rangeLabel = meta ? `Page ${meta.current_page} of ${meta.last_page} · ${meta.total} total` : '';

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="flex flex-col gap-6">
      <header className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h1 className="font-display text-3xl text-fg">Users</h1>
          <p className="mt-1 max-w-xl text-sm text-fg-muted">
            Manage buyer, staff, and superadmin accounts. Deactivate to revoke access without
            deleting history.
          </p>
        </div>
        <LinkButton to="/user-admin/new">New user</LinkButton>
      </header>

      <div className="flex flex-wrap items-center gap-3">
        <Segmented options={ROLE_TABS} value={role} onChange={setRole} ariaLabel="Filter by role" />
        <Segmented options={STATUS_TABS} value={status} onChange={setStatus} ariaLabel="Filter by status" />
      </div>

      <Card padding="lg" className="flex flex-col gap-4">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[16rem] flex-1">
            <Input
              type="search"
              label="Search"
              placeholder="Search name, email, company, or id…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
            />
          </div>
          <ListFilters fields={fields} value={filters} onChange={setFilters} />
          <Select
            label="Per page"
            className="w-28"
            value={String(perPage)}
            onChange={(e) => setPerPage(Number(e.target.value))}
          >
            {PER_PAGE_OPTIONS.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </Select>
        </div>
        <FilterBadges fields={fields} value={filters} onChange={setFilters} />
      </Card>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={users.length === 0}
        emptyTitle="No users match these filters."
        onRetry={load}
      >
        <Card padding="none" className="overflow-hidden">
          {/* Desktop: scannable table with sortable Name/Joined headers. */}
          <div className="hidden overflow-x-auto md:block">
            <table className="w-full min-w-[44rem] text-left text-sm">
              <thead className="border-b border-border text-xs uppercase tracking-wide text-fg-subtle">
                <tr>
                  <th className="px-4 py-2 font-medium">
                    <button type="button" onClick={() => toggleSort('name')} className="inline-flex items-center hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                      Name<SortCaret active={sortKey === 'name'} dir={sortDir} />
                    </button>
                  </th>
                  <th className="px-4 py-2 font-medium">Role</th>
                  <th className="px-4 py-2 font-medium">Company</th>
                  <th className="px-4 py-2 font-medium">
                    <button type="button" onClick={() => toggleSort('created')} className="inline-flex items-center hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                      Joined<SortCaret active={sortKey === 'created'} dir={sortDir} />
                    </button>
                  </th>
                  <th className="px-4 py-2 font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {users.map((u) => (
                  <tr
                    key={u.id}
                    onClick={() => navigate(`/user-admin/${u.id}`)}
                    className="cursor-pointer transition-colors hover:bg-surface-2"
                  >
                    <td className="px-4 py-3">
                      <p className="font-medium text-fg">{u.name}</p>
                      <p className="text-sm text-fg-muted">{u.email}</p>
                    </td>
                    <td className="px-4 py-3"><RoleBadge role={u.role} /></td>
                    <td className="px-4 py-3 text-fg-muted">{u.company?.name ?? '-'}</td>
                    <td className="px-4 py-3 text-fg-muted">{new Date(u.created_at).toLocaleDateString()}</td>
                    <td className="px-4 py-3"><ActiveBadge active={u.active} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile: stacked cards. */}
          <ul className="flex flex-col divide-y divide-border md:hidden">
            {users.map((u) => (
              <li key={u.id}>
                <button
                  type="button"
                  onClick={() => navigate(`/user-admin/${u.id}`)}
                  className="flex w-full items-center gap-4 px-4 py-3 text-left transition-colors hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <div className="min-w-0 flex-1">
                    <p className="block w-full truncate font-medium text-fg">{u.name}</p>
                    <p className="block w-full truncate text-sm text-fg-muted">{u.email}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-2">
                      <RoleBadge role={u.role} />
                      <ActiveBadge active={u.active} />
                    </div>
                  </div>
                  <div className="shrink-0 text-right">
                    <p className="text-sm text-fg-muted">{u.company?.name ?? '-'}</p>
                  </div>
                </button>
              </li>
            ))}
          </ul>
        </Card>
      </AsyncBoundary>

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between gap-4">
          <span className="text-sm text-fg-muted">{rangeLabel}</span>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={loading || meta.current_page <= 1} onClick={() => setPage((n) => Math.max(1, n - 1))}>
              Prev
            </Button>
            <Button variant="outline" size="sm" disabled={loading || meta.current_page >= meta.last_page} onClick={() => setPage((n) => n + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </Motion>
  );
}
```

(Numbered pagination replaces the Prev/Next block in Task 3; it is left intact here so this task is independently shippable.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/UserAdminPage.test.tsx`
Expected: PASS (tab sets `role=staff_admin`; Name header flips `sort=name_desc`).

- [ ] **Step 6: Typecheck**

Run: `cd frontend && npm run typecheck`
Expected: no errors. (`cn` is exported from `../ui` — it is used elsewhere in the app; if the import path differs, match how other pages import `cn`.)

- [ ] **Step 7: Commit**

```bash
git add frontend/src/lib/userListFilters.ts frontend/src/pages/UserAdminPage.tsx frontend/src/pages/UserAdminPage.test.tsx
git commit -m "feat(admin): surfaced role/status filters + sortable users table"
```

---

## Task 3: Frontend — numbered pagination

**Files:**
- Modify: `frontend/src/pages/UserAdminPage.tsx` (pagination block only)
- Test: `frontend/src/pages/UserAdminPage.test.tsx` (add a case)

- [ ] **Step 1: Add the failing test**

Append to `frontend/src/pages/UserAdminPage.test.tsx`:

```tsx
it('renders numbered pages and loads the page that is clicked', async () => {
  get.mockReset();
  get.mockImplementation((url: string) => {
    if (url === '/admin/companies') return Promise.resolve({ data: { data: [] } });
    return Promise.resolve({
      data: { data: [USER], meta: { current_page: 1, last_page: 3, per_page: 15, total: 45 } },
    });
  });
  renderPage();
  await waitFor(() => expect(screen.getByText('Dana Buyer')).toBeTruthy());
  await userEvent.click(screen.getByRole('button', { name: '3' }));
  await waitFor(() => expect(lastUsersParams().page).toBe(3));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd frontend && npx vitest run src/pages/UserAdminPage.test.tsx -t "renders numbered pages"`
Expected: FAIL — there is no page-number button "3" yet (only Prev/Next).

- [ ] **Step 3: Add a pageWindow helper and numbered controls**

In `frontend/src/pages/UserAdminPage.tsx`, add this helper above the `UserAdminPage` component (e.g. after `SortCaret`):

```tsx
/** Page numbers to show: first, last, and a window around current, with -1 as an ellipsis marker. */
function pageWindow(current: number, last: number): number[] {
  const pages = new Set<number>([1, last, current, current - 1, current + 1]);
  const sorted = [...pages].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);
  const out: number[] = [];
  let prev = 0;
  for (const p of sorted) {
    if (prev && p - prev > 1) out.push(-1);
    out.push(p);
    prev = p;
  }
  return out;
}
```

Replace the entire pagination block (the `{meta && meta.last_page > 1 && ( … )}` at the end of the component) with:

```tsx
      {meta && meta.last_page > 1 && (
        <div className="flex flex-wrap items-center justify-between gap-4">
          <span className="text-sm text-fg-muted">{rangeLabel}</span>
          <div className="flex items-center gap-1">
            <Button variant="outline" size="sm" disabled={loading || meta.current_page <= 1} onClick={() => setPage((n) => Math.max(1, n - 1))}>
              Prev
            </Button>
            {pageWindow(meta.current_page, meta.last_page).map((p, i) =>
              p === -1 ? (
                <span key={`gap-${i}`} className="px-2 text-fg-subtle" aria-hidden="true">…</span>
              ) : (
                <Button
                  key={p}
                  variant={p === meta.current_page ? 'primary' : 'outline'}
                  size="sm"
                  aria-current={p === meta.current_page ? 'page' : undefined}
                  disabled={loading}
                  onClick={() => setPage(p)}
                >
                  {p}
                </Button>
              ),
            )}
            <Button variant="outline" size="sm" disabled={loading || meta.current_page >= meta.last_page} onClick={() => setPage((n) => n + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/UserAdminPage.test.tsx`
Expected: PASS (all cases, including page "3" click → `page: 3`).

- [ ] **Step 5: Typecheck**

Run: `cd frontend && npm run typecheck`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/UserAdminPage.tsx frontend/src/pages/UserAdminPage.test.tsx
git commit -m "feat(admin): numbered pagination for the users list"
```

---

## Self-Review

- **Spec coverage (Piece B):** wider search — company + id (Task 1); surfaced role tabs + status toggle (Task 2); scannable table with sortable Name/Joined headers + mobile cards (Task 2); numbered pagination (Task 3). Covered.
- **Placeholders:** none — full backend clause, full page rewrite, full pagination block, exact tests + commands.
- **Type consistency:** `SortKey`/`SortDir` defined in Task 2 and reused by `pageWindow`-adjacent code in Task 3; `sort` string built as `${sortKey}_${sortDir}` matches the API whitelist (`name_asc|name_desc|created_asc|created_desc`); `Meta`, `AdminUser`, `AdminCompany`, `RoleBadge`, `ActiveBadge` unchanged from the current file. `userFilterFields` now returns only company/created, and `userFiltersToParams` (unchanged) emits only those keys, so role/status/sort come solely from page state — no double-emit.
- **Fallout:** removing role/status/sort from the popup means `FilterBadges` no longer shows them (correct — they have visible controls now). The page rewrite preserves search, company filter, per-page, empty/error/loading via `AsyncBoundary`, and row→detail navigation.
- **D hook:** the broadened `q` (company join) and `sort` on name/created are the load-bearing queries for the piece-D index pass.
