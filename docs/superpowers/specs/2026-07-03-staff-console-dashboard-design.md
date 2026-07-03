# Staff Console — Sidebar Shell + Dashboard

Date: 2026-07-03
Status: Approved (design), pending implementation plan

## Problem

Staff (`staff_admin`, `superadmin`) and buyers share one top-bar `Layout`. Staff
already carry 6+ nav destinations (Products, Kits, Track, Catalogue Gate,
Production, Procurement, Quotes) and more features are coming — the top bar does
not scale. Staff also have no landing overview; login drops them straight onto
`/catalogue-admin` with no bird's-eye view of app activity.

## Goals

1. Dedicated left-sidebar shell for staff roles; buyers + public keep the
   existing top-bar `Layout` unchanged.
2. A staff dashboard at `/dashboard` giving an at-a-glance overview of quotes,
   production, procurement, catalogue gate, and live activity.
3. All dashboard queries are scale-safe: index-backed, bounded, no N+1, cached.

## Non-goals (YAGNI)

- Date-range filters, charting library, per-widget configuration.
- Historical trend lines.
- Changing buyer/public navigation or any existing list endpoint.
- Reworking existing staff pages beyond re-parenting them under the new shell.

## Roles

`isStaffRole(role)` = `staff_admin || superadmin` (existing allowlist, fail-safe).
Value-booked widget is `superadmin`-only.

---

## 1 · Routing + shell

**New component `StaffLayout`** (`frontend/src/components/StaffLayout.tsx`):
- Persistent left sidebar (desktop) + thin top strip (brand, user, Log out).
- Sidebar sections: **Dashboard, Quotes, Production, Procurement, Catalogue Gate**.
- Sidebar item badges: Production (overdue jobs), Procurement (line items to
  reconfirm), Catalogue Gate (pending publish), Quotes/Proofs (proofs pending).
  Badge counts come from the same dashboard payload (shared store) — no extra
  requests.
- Mobile: sidebar becomes an off-canvas drawer reusing the existing
  `MobileDrawer` focus-trap/scroll-lock pattern from `SiteHeader`; hamburger in
  the top strip. Touch targets ≥44px (consistent with prior mobile pass).
- Renders `<Outlet>` for staff pages.

**Routing (`App.tsx`):**
- Add a `staffOnly` layout route wrapping `StaffLayout`; move staff routes under
  it: `/dashboard` (new), `/quotes`, `/quotes/:id`, `/production-queue`,
  `/procurement`, `/catalogue-admin`.
- Buyer/public routes stay under the existing `Layout`. `/quotes` for a **buyer**
  stays under `Layout` (buyers are not staff) — split by role at the route guard,
  not by duplicating pages. Concretely: keep buyer-facing `/quotes` under
  `Layout`; staff reach quotes via the staff shell. If a single `/quotes` page
  serves both, wrap it so staff render inside `StaffLayout`, buyers inside
  `Layout` (decided in the plan; default: shared page, shell chosen by role).
- Existing `ProtectedRoute staffOnly` guard is reused unchanged for gating.

**Login redirect (`LoginPage.tsx`):** staff → `/dashboard` (was
`/catalogue-admin`); buyers unchanged (`/quotes`); explicit `from` still wins.

---

## 2 · Dashboard page

`frontend/src/pages/DashboardPage.tsx` at `/dashboard` (staffOnly). One data call
to `GET /api/admin/dashboard`, rendered as widgets. Loading = skeletons; error =
existing `ErrorState` with retry; each widget degrades independently (a null
section shows a muted "unavailable", never blocks the page).

Widgets:
- **Quote pipeline** — count per `quotes.state`; CSS bar row (no chart lib).
- **Production health** — count per `production_jobs.state` + WIP + overdue count.
- **At-risk** — capped list (≤15) of jobs breaching SLA (see §3 definition),
  each links to the production queue.
- **Live activity feed** — recent `audit_logs` (actor, event, subject, when),
  capped 20; realtime-appended from existing Reverb channels (no polling).
- **Action-queue tiles** — Proofs pending / Procurement to-reconfirm / Catalogue
  pending; each a count that links to its page and feeds the sidebar badges.
- **Value booked** (`superadmin` only) — sum of `quotes.total` in the booked
  state-set (see §3); omitted from payload for non-superadmin.

A small Zustand `dashboardStore` holds the payload so both `DashboardPage` and
`StaffLayout` badges read one snapshot; refreshed on mount and on relevant Reverb
events (debounced).

---

## 3 · Backend endpoint

`GET /api/admin/dashboard` — `auth:sanctum` + staff gate (mirror existing admin
routes / policy). Read-only. Returns one aggregated JSON snapshot:

```
{
  pipeline:    { DRAFT: n, SENT: n, ..., CANCELLED: n },     // GROUP BY state
  production:  { byState: { READY: n, IN_PRODUCTION: n, ... }, wip: n, overdue: n },
  atRisk:      [ { jobId, quoteId, track, readyAt, breach } ],  // ≤15
  queues:      { proofsPending: n, procurementToReconfirm: n, cataloguePending: n },
  activity:    [ { id, actor, event, auditableType, auditableId, at } ], // ≤20
  valueBooked: { currency, amount } | null                    // superadmin only
}
```

**State definitions:**
- Booked state-set (value + as "booked" pipeline): `ACCEPTED, PROOFING,
  PROOF_APPROVED, PO_ISSUED, CONFIRMED, PROCURING, READY` (excludes DRAFT/SENT/
  CHANGES_REQUESTED/CLOSED/CANCELLED). No accepted-at timestamp column exists, so
  v1 reports **open booked value** (no month filter) — avoids adding a date
  column/backfill. A time-boxed variant is a later enhancement sourced from
  `audit_logs` acceptance events.
- Overdue / at-risk: **no customer due-date column exists**. Confirmed: the
  designer's "Need it by" (`needBy`) is ephemeral UI state in
  `ProductDesignerPage.tsx` and is never persisted. So at-risk =
  `production_jobs` where `state IN (READY, IN_PRODUCTION)` and
  `now > ready_at + lead_time(print_method)` (lead time from existing pricing/
  lead-time config). Uses the existing `(state, ready_at)` index. Persisting a
  customer needed-by date is a separate future feature.

**Controller/service:** `DashboardController@index` delegates to a
`DashboardMetrics` service (one method per widget, each a single indexed query),
so each unit is independently testable.

---

## 4 · Performance (scale-safe) — REQUIRED

Existing indexes already cover most aggregates (verified against migrations):
- `quotes`: `state`, `(company_id,state)` → pipeline GROUP BY.
- `production_jobs`: `state`, `ready_at`, `(state,ready_at)` → state counts,
  overdue, at-risk.
- `line_items`: `line_state`, `(quote_id,line_state)` → procurement queue.
- `proofs`: `state`, `(quote_id,state)` → pending proofs.
- `products`: `publish_state`, `(class,publish_state)` → catalogue gate.

**Index migration (new):**
- Add `index(created_at)` on `audit_logs` — feed does `ORDER BY created_at DESC
  LIMIT 20` and no such index exists today.
- If a customer needed-by column is confirmed, add its index at that time.

**Query rules (enforced in the service, checked in review):**
1. **DB-side aggregation only** — `COUNT` / `GROUP BY` / `SUM` on indexed columns.
   Never load rows into PHP to count.
2. **Bounded** — activity `LIMIT 20`, at-risk `LIMIT 15`. No unbounded `SELECT`.
3. **No N+1** — activity + at-risk use `with()` eager loads (actor, quote) and
   explicit column selects; never `SELECT *`.
4. **Short-TTL cache (~45s)** on the counts block via `Cache::remember`, global
   key. Cuts repeat DB load when many staff open the dashboard. The live activity
   feed stays realtime via Reverb push (not part of the cached block, or cached
   separately with a shorter TTL). Cache is best-effort; a miss just recomputes.
5. **No full-list fetches** — dashboard returns counts + capped slices only, never
   quote/order lists.
6. **List endpoints unchanged** — existing paginated+indexed quote/production
   endpoints are not bypassed by the dashboard.

Result: ~7 indexed counts + 2 capped, eager-loaded slices, cached 45s → sub-ms
and flat cost as data grows.

---

## 5 · Testing

**Backend (Feature):**
- Staff receives full payload; buyer → 403; unauthenticated → 401.
- `valueBooked` present for superadmin, `null`/absent for `staff_admin`.
- Pipeline/production/queue counts correct against seeded fixtures.
- At-risk returns only SLA-breaching jobs, capped at 15.
- Activity returns ≤20 newest audit rows, newest first.

**Backend (perf guard):** assert bounded queries — e.g. count DB queries on the
endpoint stays constant regardless of row volume (no N+1); optionally assert
`LIMIT` present on slice queries.

**Frontend:**
- Dashboard renders each widget from a mocked payload.
- Loading (skeleton), empty, and error (retry) states.
- Staff-role redirect: login as staff lands on `/dashboard`; buyer does not.
- Sidebar badges reflect the shared store snapshot.
- Non-superadmin does not render the value-booked widget.

## Open items for the plan

1. Decide shared-vs-split `/quotes` page rendering across shells (default:
   shared page, shell chosen by role).
