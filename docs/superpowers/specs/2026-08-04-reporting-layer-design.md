# Business Reporting Layer (Point 6) — Design Spec

**Date:** 2026-08-04
**Status:** Approved (brainstorming), pending spec review
**Decisions:** Core business set (revenue trend + top products + repeat-customer
rate + CSV export). Revenue trend carries **two series** — bookings and billed.
Revenue is **net of GST**. Repeat-customer rate = **lifetime ≥2 orders** among
range-active companies. New `/admin/reports` page, gated by a new sensitive
`reports.view` permission.

---

## Problem

`DashboardMetrics` is point-in-time only — current counts + `valueBooked` (sum of
currently-booked quotes). There is no way to see revenue **over time**, which
products sell, how many customers return, or to hand the accountant a file. Point
6 adds a reporting layer alongside (not replacing) the dashboard.

---

## Goals

1. Revenue trend over a selected range, two monthly series: **bookings** (order
   value at acceptance) and **billed** (invoiced value), both net of GST.
2. Top products by revenue over the range.
3. Repeat-customer rate.
4. A CSV order/invoice export for the accountant.
5. Gate the whole thing behind a delegable, finance-sensitive permission.

## Non-goals

- Production throughput / cycle-time analytics (separate roadmap item).
- Heavy new charting/frontend dependencies — a lightweight built-in
  visualization only.
- Real-time streaming — a few-minute cache is fine.
- Editing/writing anything — reports are strictly read-only.

---

## Design

### Access — new `reports` permission

Add a `reports` section to `App\Support\Permissions::CATALOG`:

```
'reports' => ['label' => 'Reports', 'actions' => ['view' => 'View business reports & exports']],
```

Add `'reports'` to `Permissions::SENSITIVE_SECTIONS` — like `pricing`/`users`, it
holds financial data, so it is grantable but never in the grandfather default and
only by a superadmin. Routes gate on `permission:reports.view`; the frontend route
guards on `reports.view` (mirrors `pricing-admin`).

### Backend — `ReportingService` + `ReportsController`

New `app/Services/Reporting/ReportingService.php`, following `DashboardMetrics`'
discipline: every method one index-backed aggregate query, cached a few minutes,
no row hydration for aggregates, no N+1. All methods take a resolved
`from`/`to` `CarbonImmutable` range.

**Definitions (locked):**
- An **order** = a quote with `accepted_at` not null and `state != CANCELLED`.
- **Net revenue** = amount − GST (a quote's `total - gst_amount`; an invoice's
  `amount - gst_amount`). GST is a remittable liability, not income.

**Methods:**

1. `revenueTrend(from, to): array` — two monthly series over the range:
   - **bookings**: `SUM(total - gst_amount)` grouped by `YEAR-MONTH(accepted_at)`,
     orders only (accepted, not cancelled), `accepted_at` in range.
   - **billed**: `SUM(amount - gst_amount)` over invoices grouped by
     `YEAR-MONTH(issued_at)`, `payment_state != VOID`, `issued_at` in range.
   - Returns a month-keyed array with both figures per month (zero-filled for
     months in range with no activity, so the trend has no gaps).

2. `topProducts(from, to, limit = 10): array` — `SUM(unit_price * qty)` from the
   line items of orders (accepted, not cancelled, `accepted_at` in range), grouped
   by `product_id`, ordered desc, limited. Line `unit_price` is pre-GST, so this is
   naturally net goods revenue (excludes the flat customization/setup fee, which is
   not product revenue). Returns product id, name, units, revenue.

3. `repeatCustomerRate(from, to): array` — among companies **active in range**
   (≥1 order with `accepted_at` in range), the share whose **lifetime** order count
   (orders all-time, same order definition) is ≥2. Returns
   `{ activeCompanies, repeatCompanies, rate }` (rate 0–1). Two grouped queries:
   the range-active company ids, then a lifetime `COUNT` per those ids.

Cache each under a key incorporating the range (e.g.
`reports.revenueTrend.{from}.{to}`), TTL a few minutes. Point-in-time freshness
is not required for historical reporting.

`ReportsController::index(ReportRequest)` returns all three as JSON. A
`ReportRequest` validates `from`/`to` (`date`, `from <= to`, sane bounds) and
resolves the preset/custom range; default = last 90 days.

### Backend — CSV export

`ReportsController::export(ReportRequest)` streams `text/csv` (a
`StreamedResponse`, so a large range never buffers in memory). One row per order
(orders as defined above, `accepted_at` in range), columns:

```
reference, company, accepted_at, invoice_ref, issued_at, state, payment_state,
subtotal, gst_amount, total, amount_paid, currency
```

Carries **both** dates so the accountant can pivot on acceptance or invoice basis.
Filename `giftlab-orders-{from}-{to}.csv`. Route `permission:reports.view`. Not
cached (streamed, bounded by range).

### Frontend — `/admin/reports`

New `ReportsPage.tsx` + route under the authenticated staff area, guarded
`<ProtectedRoute permission="reports.view">` (mirrors `pricing-admin`), plus a nav
entry in the staff console. Contents:
- A **range control** — presets (This month / Last month / Last 90 days / YTD /
  Custom) + custom from–to pickers. Default last 90 days.
- **Revenue trend** — a month table (Month · Bookings · Billed) plus a lightweight
  built-in bar visualization (CSS/inline SVG, no new dependency). If
  `frontend/package.json` already carries a chart lib, use it; otherwise the SVG
  bars.
- **Top products** — a table (Product · Units · Revenue).
- **Repeat-customer rate** — a single stat (rate % + the active/repeat counts).
- **Download CSV** — a button hitting the export endpoint for the current range.

All figures labelled "net of GST" so the number is never mistaken for the invoice
headline.

---

## Data flow

```
/admin/reports (reports.view) → ReportsController::index(from,to)
    → ReportingService::revenueTrend / topProducts / repeatCustomerRate  (cached)
    → JSON → ReportsPage renders tables + bars + stat

Download CSV → ReportsController::export(from,to) → StreamedResponse text/csv
```

## Error handling

- Invalid range (`from > to`, unparseable dates) → 422 from `ReportRequest`.
- No data in range → zero-filled trend, empty top-products, `rate = 0` with
  `activeCompanies = 0` (never divide-by-zero).
- Non-permitted staff / buyer → 403 (route middleware).

## Testing (Pest + Vitest)

**Backend:**
- `revenueTrend`: seed orders across ≥3 months + invoices; assert per-month
  bookings and billed are net of GST, cancelled orders excluded from bookings, VOID
  invoices excluded from billed, months with no activity zero-filled.
- `topProducts`: seed multi-line orders; assert ordering by net revenue, limit
  respected, customization fee not counted.
- `repeatCustomerRate`: a company with 1 order and one with 3; assert
  `activeCompanies`/`repeatCompanies`/`rate`; divide-by-zero → 0 when none active.
- `export`: correct CSV header + one row per order + GST column present; both date
  columns populated; streamed with the right `Content-Type`/filename.
- Permission gate: buyer and unpermitted staff_admin → 403; superadmin → 200;
  `reports` is in `SENSITIVE_SECTIONS` (not in `defaults()`).

**Frontend:**
- Range preset changes refetch; default is last 90 days.
- Trend table + top-products table render from a mocked payload; repeat-rate stat
  renders; Download button targets the export URL with the current range.
- Route is permission-gated (unpermitted → redirected, like `pricing-admin`).

---

## Files touched

**Backend (new)**
- `app/Services/Reporting/ReportingService.php`
- `app/Http/Controllers/ReportsController.php`
- `app/Http/Requests/ReportRequest.php`
- `tests/Feature/ReportingTest.php`

**Backend (modified)**
- `app/Support/Permissions.php` (add `reports` section + SENSITIVE_SECTIONS)
- `routes/api.php` (`/admin/reports`, `/admin/reports/export`, `permission:reports.view`)

**Frontend (new)**
- `frontend/src/pages/ReportsPage.tsx` (+ test)
- a small reports API client / store (follow the dashboard's fetch pattern)

**Frontend (modified)**
- `frontend/src/App.tsx` (route)
- the staff console nav (add a Reports entry, gated on `reports.view`)

## Open items for the owner

- Confirm which staff roles should be granted `reports.view` (default: superadmin
  only, since it's sensitive).
