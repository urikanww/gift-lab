# Superadmin User Management — Design

**Date:** 2026-07-06
**Status:** Approved for planning
**Goal:** Give superadmin a surface to provision, edit, and deactivate/reactivate
every account (staff + buyers), filling the biggest missing admin capability —
today there is no user-management endpoint or UI at all.

---

## Context (verified)

- Roles (`App\Enums\UserRole`): `buyer`, `staff_admin`, `superadmin`.
  `isStaff()` = staff_admin|superadmin; `isSuperadmin()` = superadmin.
- `User` model already uses `SoftDeletes` — deactivate/reactivate needs **no
  schema change** (deactivate = soft-delete, reactivate = restore).
- Buyers carry `company_id` (belong to a `Company`); staff have `company_id = null`.
- No `admin/users` routes, no `AdminUserController`, no management UI exists.
- Buyers can self-register (WIP `RegisterRequest`/`RegisterPage`); staff are seeded.
- Existing admin routes guard on `isStaff()`; user management must guard on
  `isSuperadmin()` (stricter).
- Sanctum SPA cookie auth resolves the user through the model's default scope,
  which excludes soft-deleted rows — so a deactivated user's next request 401s
  (session effectively dies). Implementation must confirm this and, for
  token-based clients, also revoke the user's Sanctum tokens on deactivate.

## Decisions (approved)

- **Access:** superadmin-only.
- **Scope:** superadmin manages everyone — staff (staff_admin + other
  superadmins) and buyers across all companies.
- **Terminate semantics:** soft Deactivate + Reactivate (existing SoftDeletes).
  No hard delete.
- **Creation:** superadmin can create staff and buyer accounts with an initial
  password (buyers self-register too; that flow is unchanged).
- Password reset: superadmin sets a new password directly (no email flow in v1).

---

## Architecture

A dedicated Users admin surface mirroring the just-shipped Products management
pattern: a server-driven list page, a standalone create page, and a detail/edit
page. Backend is one focused `AdminUserController`; a tiny companies-list
endpoint feeds the buyer company picker.

---

## Backend

### `AdminUserController` (all methods: `abort_unless($request->user()->isSuperadmin(), 403)`)

- **`index`** — paginated (default 15, max 100). Filters: `role`
  (buyer|staff_admin|superadmin), `status` (active | deactivated | all), `company`
  (company_id), `q` (name/email LIKE). Order by name. Returns `data` + pagination
  `meta` (current_page/last_page/per_page/total).
- **`store`** — create a user. Rules:
  - `name` required string; `email` required email, unique on `users.email`;
  - `password` required, min 8 (mirror the existing register/auth rule), hashed;
  - `role` required in {buyer, staff_admin, superadmin};
  - `company_id`: **required + exists when role = buyer**; **must be null/omitted
    for staff** (force null server-side).
  - Audit `user.created`.
- **`update`** — edit `name`, `email` (unique ignoring self), `role`, `company_id`
  (same buyer/staff company rule). Guardrails below. Audit `user.updated`.
- **`deactivate`** (`DELETE /admin/users/{user}`) — soft-delete; revoke the
  user's Sanctum tokens. Guardrails below. Audit `user.deactivated`.
- **`reactivate`** (`POST /admin/users/{user}/reactivate`, route `withTrashed`) —
  restore. 422 if not trashed. Audit `user.reactivated`.
- **`resetPassword`** (`POST /admin/users/{user}/password`) — set a new password
  (validated min 8, hashed). Audit `user.password_reset` (never log the value).
- **Serialize:** `{ id, name, email, role, company: {id,name}|null, active
  (=!trashed), created_at }`. Never expose password/remember_token.

### Companies picker
- **`GET /admin/companies`** — superadmin-only; returns `[{id, name}]` for the
  buyer company dropdown. (Read-only; company CRUD is out of scope.)

### Routes (new group, superadmin-guarded in-controller)
```
GET    /admin/users
POST   /admin/users
GET    /admin/users/{user}            (withTrashed)
PATCH  /admin/users/{user}            (withTrashed)
DELETE /admin/users/{user}            (deactivate)
POST   /admin/users/{user}/reactivate (withTrashed)
POST   /admin/users/{user}/password
GET    /admin/companies
```
`{user}` uses `withTrashed` binding where a deactivated user must resolve
(show/update/reactivate).

### Guardrails (must be enforced server-side, with clear 422 messages)
1. **No self-deactivation** and no self-demotion (a superadmin cannot deactivate
   or change their own role) — prevents self-lockout.
2. **Last-superadmin protection** — cannot deactivate, nor demote via role change,
   the last remaining active superadmin.
3. Email uniqueness respected; a deactivated user's email stays taken — reactivate
   rather than recreate.

---

## Frontend

- **Nav:** add **"Users"** to the staff sidebar, rendered only for superadmin
  (mirror how Pricing is gated in `useStaffNav`).
- **`/admin/users`** — server-driven list: search + filters (role, status,
  company) + pagination; each row (name, email, role badge, company, active/
  deactivated badge) links to detail; "New user" button.
- **`/admin/users/new`** — create form: name, email, role select, **company
  picker shown only when role = buyer** (from `GET /admin/companies`), initial
  password. On success → navigate to the new user's detail page.
- **`/admin/users/:id`** — detail/edit: edit name/email/role/company; **Deactivate**
  / **Reactivate** (by state); **Reset password**. Guardrail-blocked actions
  (self, last superadmin) are disabled with an explanatory tooltip/text. Deactivated
  users show a banner and read-only-ish state with Reactivate offered.

---

## Testing

- Superadmin creates a staff user and a buyer user (buyer requires a valid
  company; buyer without company → 422; staff with company → coerced null).
- Email uniqueness enforced on create + update.
- Deactivate soft-deletes and blocks login: the deactivated user's authenticated
  request returns 401; they vanish from the default (active) list and appear
  under `status=deactivated`; reactivate restores + login works again.
- **Guardrails:** self-deactivation → 422; demoting/deactivating the last active
  superadmin → 422; a second superadmin makes the action allowed.
- Password reset changes the hash (old password fails, new works).
- Access control: staff_admin and buyer hit every endpoint → 403.
- `GET /admin/companies` returns id/name and is superadmin-only.
- Frontend: list filters/pagination, create with conditional company picker,
  detail edit + deactivate/reactivate + reset password; tsc + existing suite green.

---

## Out of scope
- Company CRUD (only a read list for the picker).
- Email invitations / password-reset emails / email verification changes.
- Bulk user actions; last-login / activity tracking.
- Changes to the buyer self-registration flow.
