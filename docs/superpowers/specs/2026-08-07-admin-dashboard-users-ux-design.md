# Admin Dashboard & Users UX — Design

Date: 2026-08-07
Status: Approved for planning

## Problem

The staff-facing admin surfaces are functional but weak on UX, not decoration:

1. **Users creation policy is wrong.** Admins can create buyer accounts, but buyers
   only ever self-register (`/register` creates the company + first buyer atomically).
   Admin buyer-creation is a path that should not exist.
2. **Users are hard to find at scale.** Filters (role, company, status, joined, sort)
   are buried behind a popup; search matches only name/email; paging is Prev/Next only;
   rows are uniform and unsortable.
3. **Dashboard activity feed is unreadable to staff.** It prints raw audit rows —
   `actor · event (label)`, e.g. "Jane · quote.sent (Order 9BWV…)" — machine vocabulary
   with absolute timestamps.

Then, after the above: a **query-optimization pass**, scoped to the queries these
changes touch.

## Non-goals

- No visual "prettification" for its own sake. Changes must improve legibility, speed,
  or correctness of the staff workflow.
- **Last-active / last-login** column: deferred. No `last_login_at` data exists; adding it
  is separate scope (migration + login hook) and is not part of this spec.
- **Actionable activity feed** (only events staff act on, as clickable tasks): deferred to
  its own future spec. This spec only humanizes the existing chronological feed.
- No unrelated refactoring of the admin area.

## Scope overview

| Piece | Area | Size | Depends on |
| ----- | ---- | ---- | ---------- |
| A | Users — creation policy | Small | — |
| B | Users — findability | Large | — |
| C | Dashboard — humanize activity | Small | — |
| D | Query optimization | Medium | A, B, C landed |

---

## A. Users — creation policy

**Goal:** Admins can create only `staff_admin` and `superadmin`. Buyers are created only
by self-registration. Buyers remain fully visible in the Users list (view / deactivate).

### Backend — `app/Http/Controllers/AdminUserController.php`
- `store()`: change role validation from `Rule::in(['buyer','staff_admin','superadmin'])`
  to `Rule::in(['staff_admin','superadmin'])`. This is the real enforcement point — the
  frontend hiding the option is not sufficient.
- Remove the buyer-only `company_id` handling in `store()` (the `requiredIf role===buyer`
  rule and the `$companyId = role==='buyer' ? …` branch). Non-buyer accounts have
  `company_id = null`.
- The existing superadmin-only guard for creating a superadmin stays unchanged.
- The index (`role` filter) still accepts `buyer` — viewing/filtering buyers is unaffected.

### Frontend — `frontend/src/pages/UserAdminCreatePage.tsx`
- Role `<Select>` offers only Staff admin and Superadmin. Default role becomes `staff_admin`.
- Remove the `company_id` select and its validation (only buyers needed a company).
- Update page copy: "Create a staff admin or superadmin account."

### Tests
- Feature (Pest): `POST /admin/users` with `role=buyer` returns 422. Creating `staff_admin`
  still succeeds. Superadmin-only guard for `superadmin` still holds.
- Frontend (vitest): create page renders only the two staff roles and no company selector.

---

## B. Users — findability

**Goal:** Find any account quickly at scale via surfaced filters, wider search, a
scannable sortable table, and numbered pagination.

### B1. Surfaced filters (frontend)
`frontend/src/pages/UserAdminPage.tsx`
- **Role tabs** — a visible segmented control above the list: All / Buyers / Staff admin /
  Superadmin. Drives the existing `role` API param. Replaces the role entry in the popup.
- **Status toggle** — visible Active / Deactivated / All (existing `status` param).
- **Company, Joined-date, Sort** stay in the `ListFilters` popup (less frequently used).
- `FilterBadges` continues to reflect popup filters.

### B2. Wider search (backend + frontend)
- Backend `AdminUserController@index`: extend the `q` match beyond name/email to also match
  **company name** (join to `companies`) and **numeric id** (exact match when `q` is
  all-digits). Case-insensitive.
- Frontend: search box placeholder updated to "Search name, email, company, or id…". No
  client behaviour change beyond copy.
- Search remains debounced (existing 300ms).

### B3. Scannable sortable table (frontend)
`frontend/src/pages/UserAdminPage.tsx`
- **Desktop (≥ md):** real table — columns: Name + email · Role · Company · Joined · Status.
  Use existing `RoleBadge` / `ActiveBadge`. Row click → `/user-admin/:id` (unchanged).
- **Mobile (< md):** keep the current stacked card row.
- **Column sort:** clickable headers for **Name** and **Joined** toggle asc/desc, driving the
  existing `sort` API param (`name_asc|name_desc|created_asc|created_desc`). The active sort
  shows a direction caret. This subsumes the popup Sort control for these two fields; the
  popup Sort may be removed once headers cover it.

### B4. Numbered pagination (frontend)
`frontend/src/pages/UserAdminPage.tsx`
- Replace Prev/Next-only with numbered pages using the existing `meta`
  (`current_page`, `last_page`, `total`). Show first/last, current ± window, and ellipses.
  Keep Prev/Next as edge affordances. No backend change (API is already paginated).

### Backend query shape (feeds into D)
- The `q` search across the `companies` join and the `sort` on `name` / `created_at` are the
  new load-bearing queries. They are designed server-side deliberately so piece D can index
  them (`users.name`, `users.created_at`, `companies.name`).

### Tests
- Feature (Pest): `q` matches by company name; `q` matches by exact numeric id; `sort`
  values order correctly; `role` tab param filters correctly; pagination `meta` correct.
- Frontend (vitest): role tabs switch the `role` param; column-header click flips `sort`;
  numbered pagination renders and page click loads the right page; table on desktop,
  cards on mobile (viewport-dependent render).

---

## C. Dashboard — humanize activity (now)

**Goal:** The existing chronological feed reads as plain English for staff. Same data,
readable presentation. (Actionable feed = separate later spec.)

### Data (unchanged)
`DashboardActivity` already carries `actor`, `event`, `auditableType`, `auditableLabel`,
`at`. No backend change required for humanization.

### Frontend
- New util `frontend/src/lib/activityHumanize.ts`:
  - `humanizeActivity(a: DashboardActivity): { icon: string; text: string; when: string }`.
  - Maps known `event` strings (e.g. `quote.sent`, `user.created`, `quote.accepted`,
    `product.updated`, …) to a sentence template using `actor` + `auditableLabel`:
    "Jane sent Order 9BWVKW". Unknown events fall back to a readable default —
    `"{actor} {event-with-dots→spaces} {label}"` — never a raw dotted token alone.
  - `when` = relative time ("2h ago", "just now"); absolute time available on hover/title.
  - Category → icon mapping (order / user / product / system).
- `frontend/src/pages/DashboardPage.tsx`: render each activity row via the humanizer
  (icon + sentence + relative time), replacing the current `actor · event (label)` markup.
- Enumerate the real `event` vocabulary from `AuditLogger` call sites during implementation;
  the fallback guarantees correctness for any missed event.

### Tests
- Frontend (vitest): known events produce the expected sentence + icon; an unknown event
  produces the readable fallback (no raw dotted token); relative time formats correctly.

---

## D. Query optimization (after A–C)

**Goal:** A measured pass over the queries these changes touch — not a blanket rewrite.

### Targets
- **User index** (`AdminUserController@index`): the new `q` search across the `companies`
  join and `sort` on `name` / `created_at`. Verify the query plan; add indexes as needed
  (`users.name`, `users.created_at`, `companies.name`). Confirm no N+1 on the company
  relation (eager-load).
- **Dashboard metrics** (`app/Services/Dashboard/DashboardMetrics.php`): pipeline counts,
  production aggregates, and the activity query (already documented as N+1-free via
  `quoteReferences`). Confirm aggregates are single-query and indexed on the columns
  grouped/filtered.

### Method
- Measure first (query log / `EXPLAIN` on a seeded large dataset), fix what the evidence
  shows, re-measure. Record before/after. No speculative indexing.

### Tests
- Existing feature tests must stay green. Add a targeted assertion or query-count guard
  (e.g. Laravel's `DB::listen` / `assertQueryCount`-style) on the user index if a specific
  N+1 is found and fixed.

---

## Rollout / ordering

1. A (creation policy) — smallest, self-contained, closes a policy gap.
2. C (humanize activity) — small, frontend-mostly, independent.
3. B (findability) — largest; backend search/sort + frontend table/tabs/paging.
4. D (query optimization) — last, informed by B's final query shape.

Each piece is independently shippable and testable.

## Open questions

- None blocking. Last-active tracking and the actionable feed are explicitly deferred; if
  either becomes wanted, it gets its own spec.
