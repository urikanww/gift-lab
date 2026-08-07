# Dashboard — KPI Tiles + Trend Chart — Design

Date: 2026-08-07
Status: Approved for planning

## Problem

The staff dashboard shows only current-snapshot data (queue counts, pipeline bars, at-risk, activity). It answers "what needs attention now" but shows **no direction over time** and no top-line KPIs. We add three actionable KPI tiles and one weekly trend chart — all from data that already exists, no new tracking.

## Non-goals

- No decoration. Explicitly NOT adding a pie/donut of pipeline or production state — those are already shown as bars; a second view of the same snapshot is noise.
- No historical snapshotting infrastructure. Trends are computed on the fly from `quotes.created_at`.
- No new state-transition timestamps (`accepted_at`, `paid_at`) — see the accepted data caveats below. This spec ships with creation-date semantics.

## Data reality (decided)

- Quotes have `total` (decimal), `state`, `created_at`. There is **no `accepted_at`** — so "this month/week" metrics are keyed on **order creation date**, clearly labelled. Accepted `BOOKED_STATES` already exists as a private const in `DashboardMetrics` (reused, promoted to usable scope as needed).
- Invoices have `amount` and `amount_paid` (nullable) but **no `paid_at`** — so "collected this month" is not computable. Instead we show **Outstanding to collect** (no date needed).
- Theme colors are CSS variables as space-separated RGB triplets (e.g. `--color-fg: 20 20 26`, `--color-primary`). Recharts colors use `rgb(var(--color-…))` so the chart follows light/dark.

## Design

### 1. Three KPI tiles (reuse the existing `StatTile` pattern — clickable, route to a filtered list)

| Tile | Value | Source | Click target |
| ---- | ----- | ------ | ------------ |
| Orders this week | count of quotes with `created_at >= now-7d` | `quotes` | `/quotes` |
| Booked value (this month) | Σ `total` for quotes in `BOOKED_STATES` with `created_at` in the current calendar month; currency SGD | `quotes` | `/quotes` |
| Outstanding to collect | Σ `(amount - COALESCE(amount_paid, 0))` over issued invoices not fully paid; currency SGD | `invoices` | unpaid orders list (mirror the existing `unpaidDelivered` queue target `/quotes?filter=delivered_unpaid`, or the closest existing filter) |

"Booked value (this month)" is labelled with its creation-date basis so staff aren't misled.

### 2. Trend chart — "Orders & booked value · last 8 weeks"

- 8 weekly buckets (oldest→newest), each `{ weekStart: ISO date, orders: int, bookedValue: float }`.
- `orders` = count of quotes created in that week. `bookedValue` = Σ `total` for quotes in `BOOKED_STATES` created in that week.
- Rendered with Recharts `ComposedChart`: bars = `orders` (left axis), line = `bookedValue` (right axis). Legend, tooltip, responsive width.
- The only view on the dashboard that shows direction over time.

### 3. Backend — `app/Services/Dashboard/DashboardMetrics.php`

- Add `kpis(): array` → `{ ordersThisWeek: int, bookedThisMonth: {currency,amount}, outstanding: {currency,amount} }`.
- Add `trends(): array` → list of 8 `{ weekStart, orders, bookedValue }`, computed with grouped queries by week (DB-portable: bucket in PHP from a single `created_at`-ranged fetch, or `groupBy` on a week expression — the plan picks the portable approach since tests run on sqlite and prod is MySQL).
- Fold both into the `snapshot()` payload under new keys `kpis` and `trends`; cache with the same short TTL as the other metric groups.
- The outstanding calc reuses the invoice model; mirror how the existing `unpaidDelivered` queue reasons about unpaid so the two agree.

### 4. Migration

- Index `quotes.created_at` — the weekly rollup and the this-week/this-month filters scan it. Same low-risk pattern as the users piece (Piece D). `up`/`down`.

### 5. Frontend

- Add `recharts` dependency.
- `frontend/src/pages/DashboardPage.tsx`: render the 3 KPI tiles (extend the existing top tile grid or a new row) and the trend chart section.
- New `frontend/src/components/dashboard/TrendChart.tsx`: Recharts `ResponsiveContainer` + `ComposedChart`, colors via `rgb(var(--color-primary))` / a second token so it is theme-correct; formats currency + week labels.
- Types: extend the dashboard DTO (`frontend/src/lib/dashboard.ts`) with `kpis` and `trends`.

### 6. Testing

- Pest (`tests/Feature/` or a `DashboardMetricsTest`): week-boundary correctness (a quote created 8 weeks ago vs 9 lands in/out of the window), `BOOKED_STATES` filter for booked value, current-month boundary for the month KPI, outstanding = amount − amount_paid excluding fully-paid/void. Assert the `snapshot` payload carries `kpis` + `trends` with the right shape.
- Vitest: DashboardPage renders the 3 tiles with values from a mocked store; `TrendChart` renders without throwing given 8 rows (Recharts in jsdom); tiles link to the right routes.

## Rollout

1. Backend `kpis()` + `trends()` + payload + tests.
2. Migration (index `quotes.created_at`).
3. Frontend: recharts dep, DTO types, tiles, `TrendChart`, wire into `DashboardPage`.

Each step is independently testable; frontend depends on the backend payload shape from step 1.

## Open questions

- None blocking. If accurate acceptance/payment-date metrics become wanted later, adding `accepted_at`/`paid_at` (populate on state transition) is a separate spec; this design is deliberately creation-date based and labelled as such.
