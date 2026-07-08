# Superadmin User Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A superadmin-only surface to create, edit, and deactivate/reactivate every account (staff + buyers), with self-lockout and last-superadmin guardrails.

**Architecture:** One focused `AdminUserController` (superadmin-guarded) exposing list/create/show/update/deactivate/reactivate/reset-password over the `User` model (which already has SoftDeletes → deactivate=soft-delete, reactivate=restore), plus a read-only companies list for the buyer picker. Frontend mirrors the just-shipped Products management pattern: server-driven list + create page + detail/edit page, with a superadmin-gated "Users" nav entry.

**Tech Stack:** Laravel 12 + Pest (backend), React + TS + Zustand + Tailwind + Vitest (frontend), Sanctum cookie auth.

**Reference patterns to follow:** `app/Http/Controllers/AdminProductController.php` (abort_unless guard, `$this->audit->log(...)`, validate, `serialize`, pagination meta, `->withTrashed()` routes, destroy/restore), `frontend/src/pages/ProductAdminPage.tsx` / `ProductAdminCreatePage.tsx` / `ProductAdminDetailPage.tsx` (page structure), `frontend/src/components/StaffLayout.tsx` `useStaffNav` (superadmin-gated nav like Pricing), `app/Http/Requests/RegisterRequest.php` (password rule to mirror).

---

## File Structure

- Create `app/Http/Controllers/AdminUserController.php` - all user-management endpoints + companies list.
- Modify `routes/api.php` - new superadmin user routes.
- Create `tests/Feature/AdminUserManagementTest.php` - backend feature tests.
- Create `frontend/src/pages/UserAdminPage.tsx` (list), `UserAdminCreatePage.tsx`, `UserAdminDetailPage.tsx`.
- Modify `frontend/src/App.tsx` (routes), `frontend/src/components/StaffLayout.tsx` (nav), `frontend/src/types.ts` (AdminUser type).

---

## Task 1: Backend - list + companies + access guard

**Files:** Create `app/Http/Controllers/AdminUserController.php`; Modify `routes/api.php`; Create `tests/Feature/AdminUserManagementTest.php`.

- [ ] **Step 1: Failing test - superadmin lists users; staff/buyer are 403.**

Add to `tests/Feature/AdminUserManagementTest.php` (Pest, `use RefreshDatabase` is the default). Set up actors in `beforeEach`:

```php
<?php
declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->superadmin = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);
    $this->staffAdmin = User::factory()->create(['role' => 'staff_admin', 'company_id' => null]);
    $this->buyer = User::factory()->create(['role' => 'buyer', 'company_id' => $this->company->id]);
});

it('lets a superadmin list users and 403s staff/buyers', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->getJson('/api/admin/users')->assertOk()
        ->assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'company', 'active']], 'meta']);

    Sanctum::actingAs($this->staffAdmin);
    $this->getJson('/api/admin/users')->assertForbidden();
    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/users')->assertForbidden();
});

it('filters users by role, status and search', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->getJson('/api/admin/users?role=buyer')->assertOk()
        ->assertJsonPath('data.0.role', 'buyer');
    $this->getJson('/api/admin/users?q='.urlencode($this->buyer->email))->assertOk()
        ->assertJsonPath('meta.total', 1);
});

it('serves a superadmin-only companies list for the buyer picker', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->getJson('/api/admin/companies')->assertOk()
        ->assertJsonFragment(['id' => $this->company->id, 'name' => $this->company->name]);
    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/companies')->assertForbidden();
});
```

- [ ] **Step 2: Run - expect FAIL (routes/controller missing).**

Run: `cd /d/work/NexGen/gift-lab && php artisan test tests/Feature/AdminUserManagementTest.php`
Expected: FAIL (404 / method not found).

- [ ] **Step 3: Implement controller `index` + `companies` + `serialize`.**

Create `app/Http/Controllers/AdminUserController.php`. Constructor injects `App\Services\AuditLogger $audit` (used in later tasks). Every method starts `abort_unless($request->user()->isSuperadmin(), 403);`.

`index`: `$perPage = max(1, min((int)$request->integer('per_page', 15), 100));` Query `User::query()->with('company:id,name')` with:
- `status`: default `active` (not trashed); `deactivated` → `onlyTrashed()`; `all` → `withTrashed()`.
- `role`: `where('role', $role)` when in {buyer,staff_admin,superadmin}.
- `company`: `where('company_id', (int)$company)` when present.
- `q`: `whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('LOWER(email) LIKE ?', [...])` (wrap in a nested `where(function)` so it doesn't leak past other filters).
- `orderBy('name')`, `paginate($perPage)`.
Return `{ data: map(serialize), meta: {current_page,last_page,per_page,total} }`.

`serialize(User $u)`: `['id'=>$u->id,'name'=>$u->name,'email'=>$u->email,'role'=>$u->role->value,'company'=>$u->company ? ['id'=>$u->company->id,'name'=>$u->company->name] : null,'active'=>! $u->trashed(),'created_at'=>$u->created_at?->toIso8601String()]`. Never expose password.

`companies(Request $request)`: `abort_unless(isSuperadmin)`, return `['data' => Company::query()->orderBy('name')->get(['id','name'])]`.

- [ ] **Step 4: Add routes** to the authenticated group in `routes/api.php` (near the other `/admin/*` routes):

```php
Route::get('/admin/users', [AdminUserController::class, 'index']);
Route::post('/admin/users', [AdminUserController::class, 'store']);
Route::get('/admin/users/{user}', [AdminUserController::class, 'show'])->withTrashed();
Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->withTrashed();
Route::delete('/admin/users/{user}', [AdminUserController::class, 'deactivate']);
Route::post('/admin/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->withTrashed();
Route::post('/admin/users/{user}/password', [AdminUserController::class, 'resetPassword'])->withTrashed();
Route::get('/admin/companies', [AdminUserController::class, 'companies']);
```
Add `use App\Http\Controllers\AdminUserController;` at the top. (Later tasks implement store/show/update/deactivate/reactivate/resetPassword; adding the routes now is fine - unimplemented methods just aren't hit by Task 1 tests.)

- [ ] **Step 5: Run - expect PASS** (the three Task-1 tests).

Run: `cd /d/work/NexGen/gift-lab && php artisan test tests/Feature/AdminUserManagementTest.php`
Expected: PASS for list/filter/companies tests. (Other methods referenced in routes are defined as stubs returning `response()->json([], 200)` for now, or implement them in this task's controller as empty methods to avoid route-resolution errors - implement fully in Tasks 2-4.)

- [ ] **Step 6: Commit.**

```bash
git add app/Http/Controllers/AdminUserController.php routes/api.php tests/Feature/AdminUserManagementTest.php
git commit -m "feat(admin): user management list + companies picker (superadmin)"
```

---

## Task 2: Backend - create user (store)

**Files:** Modify `app/Http/Controllers/AdminUserController.php`, `tests/Feature/AdminUserManagementTest.php`.

- [ ] **Step 1: Failing tests.**

```php
it('creates a staff user (company forced null)', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->postJson('/api/admin/users', [
        'name' => 'New Ops', 'email' => 'ops2@giftlab.local',
        'password' => 'ChangeMe!123', 'role' => 'staff_admin', 'company_id' => $this->company->id,
    ])->assertCreated()->assertJsonPath('data.role', 'staff_admin')
        ->assertJsonPath('data.company', null);
    $this->assertDatabaseHas('users', ['email' => 'ops2@giftlab.local', 'company_id' => null]);
});

it('creates a buyer and requires a company', function (): void {
    Sanctum::actingAs($this->superadmin);
    // Missing company → 422
    $this->postJson('/api/admin/users', [
        'name' => 'Buyer X', 'email' => 'bx@acme.test', 'password' => 'ChangeMe!123', 'role' => 'buyer',
    ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    // With company → created
    $this->postJson('/api/admin/users', [
        'name' => 'Buyer X', 'email' => 'bx@acme.test', 'password' => 'ChangeMe!123',
        'role' => 'buyer', 'company_id' => $this->company->id,
    ])->assertCreated()->assertJsonPath('data.company.id', $this->company->id);
});

it('rejects a duplicate email', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->postJson('/api/admin/users', [
        'name' => 'Dup', 'email' => $this->buyer->email, 'password' => 'ChangeMe!123',
        'role' => 'buyer', 'company_id' => $this->company->id,
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});
```

- [ ] **Step 2: Run - expect FAIL.**

Run: `php artisan test tests/Feature/AdminUserManagementTest.php --filter="creates a"`
Expected: FAIL.

- [ ] **Step 3: Implement `store`.**

Mirror the password rule in `app/Http/Requests/RegisterRequest.php` (read it; use the same min length - e.g. `['required','string','min:8']`). Validate:
```php
$validated = $request->validate([
    'name' => ['required','string','max:255'],
    'email' => ['required','email','max:255','unique:users,email'],
    'password' => ['required','string','min:8'], // match RegisterRequest
    'role' => ['required', Rule::in(['buyer','staff_admin','superadmin'])],
    'company_id' => [Rule::requiredIf(fn () => $request->input('role') === 'buyer'), 'nullable','integer','exists:companies,id'],
]);
$isBuyer = $validated['role'] === 'buyer';
$user = User::create([
    'name' => $validated['name'], 'email' => $validated['email'],
    'password' => \Illuminate\Support\Facades\Hash::make($validated['password']),
    'role' => $validated['role'],
    'company_id' => $isBuyer ? $validated['company_id'] : null, // staff forced null
]);
$this->audit->log($user, 'user.created', null, ['role' => $user->role->value, 'email' => $user->email]);
return response()->json(['data' => $this->serialize($user->load('company'))], 201);
```

- [ ] **Step 4: Run - expect PASS.**

Run: `php artisan test tests/Feature/AdminUserManagementTest.php`
Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add app/Http/Controllers/AdminUserController.php tests/Feature/AdminUserManagementTest.php
git commit -m "feat(admin): create staff/buyer users with company + password"
```

---

## Task 3: Backend - edit + guardrails

**Files:** Modify `app/Http/Controllers/AdminUserController.php`, `tests/Feature/AdminUserManagementTest.php`.

- [ ] **Step 1: Failing tests (show, update, guardrails).**

```php
it('shows and edits a user', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->getJson("/api/admin/users/{$this->buyer->id}")->assertOk()->assertJsonPath('data.id', $this->buyer->id);
    $this->patchJson("/api/admin/users/{$this->buyer->id}", ['name' => 'Renamed'])
        ->assertOk()->assertJsonPath('data.name', 'Renamed');
});

it('blocks self-deactivation and self-demotion', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->deleteJson("/api/admin/users/{$this->superadmin->id}")->assertStatus(422);
    $this->patchJson("/api/admin/users/{$this->superadmin->id}", ['role' => 'staff_admin'])->assertStatus(422);
});

it('protects the last active superadmin', function (): void {
    // Only one superadmin exists ($this->superadmin). A second superadmin acts.
    $other = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);
    Sanctum::actingAs($other);
    // Demoting the only *other* superadmin is fine because $other remains… so target the situation:
    // deleting $this->superadmin leaves $other as the last → allowed; then deleting $other (last) → blocked.
    $this->deleteJson("/api/admin/users/{$this->superadmin->id}")->assertOk();
    $this->deleteJson("/api/admin/users/{$other->id}")->assertStatus(422); // self, and last → blocked
});
```

- [ ] **Step 2: Run - expect FAIL.**

Run: `php artisan test tests/Feature/AdminUserManagementTest.php --filter="edits a user|self-|last active"`
Expected: FAIL.

- [ ] **Step 3: Implement `show`, `update`, and shared guardrail helpers.**

`show(Request $r, User $user)`: guard, return `serialize($user->load('company'))`.

Guardrail helpers on the controller:
```php
private function isSelf(Request $r, User $u): bool { return $r->user()->id === $u->id; }
private function activeSuperadminCount(): int {
    return User::query()->where('role', 'superadmin')->count(); // default scope excludes trashed
}
private function isLastSuperadmin(User $u): bool {
    return $u->role === \App\Enums\UserRole::Superadmin && ! $u->trashed() && $this->activeSuperadminCount() <= 1;
}
```

`update(Request $r, User $user)`: guard. Validate optional fields:
```php
$validated = $r->validate([
    'name' => ['sometimes','string','max:255'],
    'email' => ['sometimes','email','max:255', Rule::unique('users','email')->ignore($user->id)],
    'role' => ['sometimes', Rule::in(['buyer','staff_admin','superadmin'])],
    'company_id' => ['sometimes','nullable','integer','exists:companies,id'],
]);
```
Guardrails BEFORE saving:
- If changing `role` away from superadmin and `$this->isSelf` → 422 "You cannot change your own role."
- If changing `role` away from superadmin and `$this->isLastSuperadmin($user)` → 422 "Cannot demote the last superadmin."
Apply role/company rule: if resulting role is buyer, `company_id` required (422 if null); if staff, force `company_id=null`. Fill, save, audit `user.updated` (before/after role+email). Return serialize.

- [ ] **Step 4: Run - expect PASS.**

Run: `php artisan test tests/Feature/AdminUserManagementTest.php`
Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add app/Http/Controllers/AdminUserController.php tests/Feature/AdminUserManagementTest.php
git commit -m "feat(admin): edit users + self-lockout & last-superadmin guardrails"
```

---

## Task 4: Backend - deactivate / reactivate / reset password

**Files:** Modify `app/Http/Controllers/AdminUserController.php`, `tests/Feature/AdminUserManagementTest.php`.

- [ ] **Step 1: Failing tests.**

```php
it('deactivates a user, blocking their access, then reactivates', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->deleteJson("/api/admin/users/{$this->buyer->id}")->assertOk()->assertJsonPath('data.active', false);
    $this->assertSoftDeleted('users', ['id' => $this->buyer->id]);
    // Deactivated user cannot use the API.
    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/user')->assertUnauthorized();
    // Reactivate.
    Sanctum::actingAs($this->superadmin);
    $this->postJson("/api/admin/users/{$this->buyer->id}/reactivate")->assertOk()->assertJsonPath('data.active', true);
    $this->assertDatabaseHas('users', ['id' => $this->buyer->id, 'deleted_at' => null]);
});

it('blocks deactivating the last active superadmin and yourself', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->deleteJson("/api/admin/users/{$this->superadmin->id}")->assertStatus(422);
});

it('resets a user password', function (): void {
    Sanctum::actingAs($this->superadmin);
    $this->postJson("/api/admin/users/{$this->buyer->id}/password", ['password' => 'BrandNew!99'])->assertOk();
    expect(\Illuminate\Support\Facades\Hash::check('BrandNew!99', $this->buyer->fresh()->password))->toBeTrue();
});
```

Note: the "deactivated user cannot use the API" assertion depends on the auth provider excluding soft-deleted users. If `Sanctum::actingAs` bypasses that (it sets the user directly), instead assert via the model resolution: after deactivate, `User::find($this->buyer->id)` is null (default scope) - add `expect(User::find($this->buyer->id))->toBeNull();`. Keep whichever assertion the implementer verifies actually reflects "blocked" in this app; document the choice in the test comment.

- [ ] **Step 2: Run - expect FAIL.**

Run: `php artisan test tests/Feature/AdminUserManagementTest.php --filter="deactivat|reset a user"`
Expected: FAIL.

- [ ] **Step 3: Implement.**

```php
public function deactivate(Request $r, User $user): JsonResponse {
    abort_unless($r->user()->isSuperadmin(), 403);
    if ($this->isSelf($r, $user)) return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
    if ($this->isLastSuperadmin($user)) return response()->json(['message' => 'Cannot deactivate the last superadmin.'], 422);
    $user->tokens()->delete();   // revoke any Sanctum tokens
    $user->delete();             // soft delete
    $this->audit->log($user, 'user.deactivated', ['role' => $user->role->value], null);
    return response()->json(['data' => $this->serialize($user->fresh(['company']))]);
}

public function reactivate(Request $r, User $user): JsonResponse {
    abort_unless($r->user()->isSuperadmin(), 403);
    if (! $user->trashed()) return response()->json(['message' => 'User is already active.'], 422);
    $user->restore();
    $this->audit->log($user, 'user.reactivated', null, ['role' => $user->role->value]);
    return response()->json(['data' => $this->serialize($user->fresh(['company']))]);
}

public function resetPassword(Request $r, User $user): JsonResponse {
    abort_unless($r->user()->isSuperadmin(), 403);
    $validated = $r->validate(['password' => ['required','string','min:8']]);
    $user->password = \Illuminate\Support\Facades\Hash::make($validated['password']);
    $user->save();
    $this->audit->log($user, 'user.password_reset', null, null); // never log the value
    return response()->json(['data' => $this->serialize($user->fresh(['company']))]);
}
```

- [ ] **Step 4: Run full file - expect PASS.**

Run: `php artisan test tests/Feature/AdminUserManagementTest.php`
Expected: PASS (all).

- [ ] **Step 5: Full backend suite (no regressions).**

Run: `php artisan test`
Expected: all pass.

- [ ] **Step 6: Commit.**

```bash
git add app/Http/Controllers/AdminUserController.php tests/Feature/AdminUserManagementTest.php
git commit -m "feat(admin): deactivate/reactivate users + password reset"
```

---

## Task 5: Frontend - nav + server-driven Users list

**Files:** Modify `frontend/src/components/StaffLayout.tsx`, `frontend/src/App.tsx`, `frontend/src/types.ts`; Create `frontend/src/pages/UserAdminPage.tsx`.

- [ ] **Step 1: Add the `AdminUser` type** to `frontend/src/types.ts`:

```ts
export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: 'buyer' | 'staff_admin' | 'superadmin';
  company: { id: number; name: string } | null;
  active: boolean;
  created_at: string;
}
```

- [ ] **Step 2: Add the nav entry** (superadmin-only) in `frontend/src/components/StaffLayout.tsx` `useStaffNav`, mirroring the Pricing gate:

```ts
    ...(isSuperadmin ? [{ to: '/user-admin', label: 'Users' }] : []),
```
(Place alongside the existing `isSuperadmin ? [{ to: '/pricing-admin', label: 'Pricing' }]` entry.)

- [ ] **Step 3: Add routes** in `frontend/src/App.tsx` (staffOnly group), lazy-loaded like the product pages:

```tsx
const UserAdminPage = lazy(() => import('./pages/UserAdminPage'));
const UserAdminCreatePage = lazy(() => import('./pages/UserAdminCreatePage'));
const UserAdminDetailPage = lazy(() => import('./pages/UserAdminDetailPage'));
// routes:
<Route path="user-admin" element={<ProtectedRoute staffOnly><UserAdminPage /></ProtectedRoute>} />
<Route path="user-admin/new" element={<ProtectedRoute staffOnly><UserAdminCreatePage /></ProtectedRoute>} />
<Route path="user-admin/:id" element={<ProtectedRoute staffOnly><UserAdminDetailPage /></ProtectedRoute>} />
```

- [ ] **Step 4: Create `frontend/src/pages/UserAdminPage.tsx`** - server-driven list, modeled on `ProductAdminPage.tsx`:
  - State: page, per_page (default 15, adjustable Select 15/30/50/100), role filter, status (active/deactivated), company filter (optional text or from `/admin/companies`), debounced `q`.
  - Fetch `GET /admin/users` with those params; read `meta` for pagination (Prev/Next + "Page X of Y").
  - Table rows: name, email, role badge, company name (or "-"), active/deactivated badge; row → `/user-admin/:id`.
  - Header: "New user" → `/user-admin/new`.
  - Use `AsyncBoundary` for loading/error/empty. Reuse Tailwind tokens; avoid fixed-px grid overflow (flexible columns / `min-w-0`, names as `block w-full truncate`).

- [ ] **Step 5: Verify.**

Run: `cd /d/work/NexGen/gift-lab/frontend && npx tsc --noEmit` (clean) and `npx vitest run` (existing pass).

- [ ] **Step 6: Commit.**

```bash
git add frontend/src/types.ts frontend/src/components/StaffLayout.tsx frontend/src/App.tsx frontend/src/pages/UserAdminPage.tsx
git commit -m "feat(admin): Users nav + server-driven user list"
```

---

## Task 6: Frontend - create user page

**Files:** Create `frontend/src/pages/UserAdminCreatePage.tsx`.

- [ ] **Step 1: Build the create form** (`/user-admin/new`), modeled on `ProductAdminCreatePage.tsx`:
  - Fields: name, email, password, role (Select buyer/staff_admin/superadmin), and a **company picker shown only when role === 'buyer'** - populate from `GET /admin/companies` (fetch on mount).
  - Submit: `await ensureCsrf(); api.post('/admin/users', payload)`. Omit `company_id` for staff roles. On success read `data.id` → `navigate('/user-admin/'+id)` with a success toast. Back link to `/user-admin`.
  - Validate lightly client-side (email format, password length ≥ 8, company required when buyer); surface backend 422 messages via toast.

- [ ] **Step 2: Verify.**

Run: `cd /d/work/NexGen/gift-lab/frontend && npx tsc --noEmit` (clean) and `npx vitest run` (existing pass).

- [ ] **Step 3: Commit.**

```bash
git add frontend/src/pages/UserAdminCreatePage.tsx
git commit -m "feat(admin): create-user page with conditional company picker"
```

---

## Task 7: Frontend - user detail/edit page

**Files:** Create `frontend/src/pages/UserAdminDetailPage.tsx`.

- [ ] **Step 1: Build detail/edit** (`/user-admin/:id`), modeled on `ProductAdminDetailPage.tsx`:
  - Fetch `GET /admin/users/:id`. Header: name, role badge, company, active/deactivated badge.
  - Edit form (PATCH changed fields): name, email, role (Select), company picker (shown when role === 'buyer', from `/admin/companies`). Save via `ensureCsrf` + `api.patch`.
  - Actions: **Deactivate** (`DELETE /admin/users/:id`) when active; **Reactivate** (`POST …/reactivate`) when deactivated; **Reset password** (small inline form → `POST …/password`).
  - Guardrail UX: disable Deactivate + role Select when the row is the current user (compare to `useAuthStore` user id) with an explanatory note; rely on backend 422 as the source of truth and toast its message on failure.
  - Deactivated users show a banner; edits disabled until reactivated.
  - Back link to `/user-admin`.

- [ ] **Step 2: Verify.**

Run: `cd /d/work/NexGen/gift-lab/frontend && npx tsc --noEmit` (clean) and `npx vitest run` (existing pass).

- [ ] **Step 3: Commit.**

```bash
git add frontend/src/pages/UserAdminDetailPage.tsx
git commit -m "feat(admin): user detail/edit + deactivate/reactivate + password reset"
```

---

## Self-Review

- **Spec coverage:** access guard (T1) ✓; list+filters (T1) ✓; companies picker (T1) ✓; create staff/buyer+company rule (T2) ✓; edit (T3) ✓; guardrails self+last-superadmin (T3, T4) ✓; deactivate/reactivate+token revoke+login-block (T4) ✓; password reset (T4) ✓; nav superadmin-only (T5) ✓; list/create/detail pages (T5–T7) ✓; testing (each task) ✓. Out-of-scope items (company CRUD, email flows, bulk, last-login) intentionally excluded.
- **Placeholder scan:** none - concrete code, paths, commands.
- **Type consistency:** `AdminUser` fields (role union, `company` shape, `active`) match the backend `serialize`; route paths (`/admin/users…`) consistent between backend routes and frontend calls; guardrail helper names (`isSelf`, `isLastSuperadmin`, `activeSuperadminCount`) consistent across Tasks 3–4.
