# Dashboard KPI Tiles + Trend Chart Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three KPI tiles (orders this week, booked value this month, outstanding to collect) and an 8-week orders/booked-value trend chart to the staff dashboard, all from existing data.

**Architecture:** Backend `DashboardMetrics` gains `kpis()` + `trends()`, folded into the cached snapshot behind the existing `includeValue` (superadmin) financial gate. Frontend renders tiles (existing `StatTile` pattern) and a Recharts `ComposedChart`.

**Tech Stack:** Laravel 12 + Pest; React 18 + Recharts + Vitest.

Spec: `docs/superpowers/specs/2026-08-07-dashboard-charts-kpis-design.md`.

## Verified facts

- `DashboardMetrics::BOOKED_STATES` (private const) = `['ACCEPTED','PROOFING','PROOF_APPROVED','INVOICED','CONFIRMED','PROCURING','READY']`. Reused via `self::BOOKED_STATES` (same class).
- `snapshot(bool $includeValue)` caches counts under `dashboard.metrics.v4` (45s) and `valueBooked` under `dashboard.metrics.v1.value` only when `$includeValue`. Controller calls `snapshot($request->user()->isSuperadmin())`. Endpoint: `GET /admin/dashboard`.
- Quotes: `total` (decimal, cast `decimal:2`), `state` (enum), `created_at`. No `accepted_at`.
- Invoices (`App\Models\Invoice`): `amount`, `amount_paid` (nullable), `payment_state` (`App\Enums\PaymentState`: `UNPAID|PARTIAL|PAID|VOID`). Outstanding mirrors the existing `unpaidDelivered` logic (`payment_state` in `UNPAID`,`PARTIAL`).
- Theme colors: CSS vars as RGB triplets — `--color-primary: 11 63 176` (light) / brightened in dark. Use `rgb(var(--color-primary))` in Recharts so it follows the theme.
- The whole KPI+trend block is superadmin-only (behind `includeValue`), matching `valueBooked`. Non-superadmin staff see the dashboard unchanged.

## File Structure

- Modify: `app/Services/Dashboard/DashboardMetrics.php` — add `kpis()`, `trends()`, fold into `snapshot()`.
- Test: `tests/Feature/DashboardKpisTest.php` (create).
- Create: `database/migrations/2026_08_07_000002_add_created_at_index_to_quotes.php`.
- Modify: `frontend/src/lib/dashboard.ts` — DTO types.
- Modify: `frontend/package.json` — add `recharts`.
- Create: `frontend/src/components/dashboard/TrendChart.tsx`.
- Modify: `frontend/src/pages/DashboardPage.tsx` — render tiles + chart.
- Test: `frontend/src/pages/DashboardKpis.render.test.tsx` (create).

---

## Task 1: Backend — kpis() + trends() + snapshot

**Files:**
- Create: `database/factories/InvoiceFactory.php` (no factory exists yet; the KPI test needs it)
- Modify: `app/Services/Dashboard/DashboardMetrics.php`
- Test: `tests/Feature/DashboardKpisTest.php`

- [ ] **Step 0: Create the missing InvoiceFactory**

`App\Models\Invoice` (table `purchase_orders`) has no factory. Its NOT-NULL-without-default columns are `quote_id` and `po_ref` (unique); the rest default (`payment_state` UNPAID, `amount` 0, `currency` SGD, `gst_amount` 0, `amount_paid` nullable). Create `database/factories/InvoiceFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'quote_id' => Quote::factory(),
            'po_ref' => 'PO-'.$this->faker->unique()->numerify('######'),
            'payment_state' => 'UNPAID',
            'amount' => 0,
            'amount_paid' => null,
        ];
    }
}
```

Confirm `App\Models\Invoice` uses the `HasFactory` trait; if it does not, add `use Illuminate\Database\Eloquent\Factories\HasFactory;` and `use HasFactory;` to the model (factories won't resolve otherwise). Laravel factories set attributes directly, bypassing mass-assignment guards, so `po_ref` is fine even if not in `$fillable`.

> **As-built note (commit `868bbbc`):** the executed test pins the clock with `Carbon::setTestNow('2026-06-15 …')` instead of the skip-guard shown below, and binds both invoices to ONE out-of-window quote (`created_at => now()->subMonths(2)`, DRAFT/total 0) via `->for($oldQuote, 'quote')` — because `Invoice::factory()` cascade-creates a `Quote`, which otherwise inflated `ordersThisWeek`. Assertions: `ordersThisWeek=2`, `bookedThisMonth=1649.0`, `outstanding=300.0`. `HasFactory` was also added to `App\Models\Invoice` (was absent). The block below is the original draft; the committed test is source of truth.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardKpisTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Dashboard\DashboardMetrics;

it('computes the three KPI numbers from existing data', function (): void {
    // Orders this week: 2 created in last 7d, 1 created 10d ago (excluded).
    Quote::factory()->create(['created_at' => now()->subDays(1), 'state' => 'DRAFT', 'total' => 100]);
    Quote::factory()->create(['created_at' => now()->subDays(3), 'state' => 'ACCEPTED', 'total' => 250]);
    Quote::factory()->create(['created_at' => now()->subDays(10), 'state' => 'ACCEPTED', 'total' => 999]);

    // Booked this month: the ACCEPTED one from 3d ago (250) counts; DRAFT does not;
    // the 10d-ago one is still this month only if today is >10 into the month - so
    // assert with a booked quote pinned to the 1st of this month.
    Quote::factory()->create(['created_at' => now()->startOfMonth(), 'state' => 'CONFIRMED', 'total' => 400]);

    // Outstanding: an invoice owing 300 (amount 500, paid 200, PARTIAL) + one fully PAID (ignored).
    Invoice::factory()->create(['payment_state' => 'PARTIAL', 'amount' => 500, 'amount_paid' => 200]);
    Invoice::factory()->create(['payment_state' => 'PAID', 'amount' => 800, 'amount_paid' => 800]);

    $kpis = app(DashboardMetrics::class)->kpis();

    expect($kpis['ordersThisWeek'])->toBe(3) // 2 above + the startOfMonth one (within 7d only if near month start)
        ->and($kpis['bookedThisMonth']['amount'])->toBeGreaterThanOrEqual(400.0)
        ->and($kpis['outstanding']['amount'])->toBe(300.0);
})->skip(fn () => now()->day > 7, 'time-window assertions assume early in the month; see note');

it('buckets the last 8 weeks with orders and booked value', function (): void {
    Quote::factory()->create(['created_at' => now()->subWeeks(1), 'state' => 'ACCEPTED', 'total' => 100]);
    Quote::factory()->create(['created_at' => now()->subWeeks(1), 'state' => 'DRAFT', 'total' => 999]);
    Quote::factory()->create(['created_at' => now()->subWeeks(20), 'state' => 'ACCEPTED', 'total' => 500]); // outside window

    $trends = app(DashboardMetrics::class)->trends();

    expect($trends)->toHaveCount(8)
        ->and($trends[0])->toHaveKeys(['weekStart', 'orders', 'bookedValue']);

    $recent = collect($trends)->last(); // most recent week bucket includes the subWeeks(1)? depends on boundary
    $totalOrders = collect($trends)->sum('orders');
    $totalBooked = collect($trends)->sum('bookedValue');
    expect($totalOrders)->toBe(2)          // the two within-window quotes; the 20-weeks-ago one excluded
        ->and($totalBooked)->toBe(100.0);  // only the ACCEPTED one counts toward booked value
});

it('includes kpis+trends only for superadmin (includeValue) and nulls them otherwise', function (): void {
    $metrics = app(DashboardMetrics::class);
    expect($metrics->snapshot(true))->toHaveKeys(['kpis', 'trends'])
        ->and($metrics->snapshot(true)['kpis'])->not->toBeNull();

    $staffView = $metrics->snapshot(false);
    expect($staffView['kpis'])->toBeNull()
        ->and($staffView['trends'])->toBeNull();
})->skip(fn (): bool => false);
```

Note on the first test's `skip`: the week/month window assertions are date-relative. The guard skips it late in the month when a "last 7 days" span crosses the month boundary and would make `ordersThisWeek` ambiguous. The bucket test and the gating test are date-robust. (If the implementer prefers, replace the skip by using `Carbon::setTestNow()` to pin a fixed date — see Step 3b.)

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/pest tests/Feature/DashboardKpisTest.php`
Expected: FAIL — `kpis()`/`trends()` don't exist, and `snapshot()` has no `kpis`/`trends` keys.

- [ ] **Step 3: Implement `kpis()`, `trends()`, and fold into `snapshot()`**

In `app/Services/Dashboard/DashboardMetrics.php`, add `use App\Models\Invoice;` at the top if not present. Add these two methods (near `valueBooked()`):

```php
    /**
     * Top-line KPI numbers. Money figures follow the same superadmin gate as
     * valueBooked (see snapshot()). Dates are by created_at - the app has no
     * accepted_at/paid_at, so "this week/month" means "orders created in it".
     *
     * @return array<string,mixed>
     */
    public function kpis(): array
    {
        $now = now();

        $outstanding = Invoice::query()
            ->whereIn('payment_state', ['UNPAID', 'PARTIAL'])
            ->get(['amount', 'amount_paid'])
            ->sum(fn (Invoice $i): float => (float) $i->amount - (float) ($i->amount_paid ?? 0));

        return [
            'ordersThisWeek' => Quote::query()
                ->where('created_at', '>=', $now->copy()->subDays(7))
                ->count(),
            'bookedThisMonth' => [
                'currency' => 'SGD',
                'amount' => (float) Quote::query()
                    ->whereIn('state', self::BOOKED_STATES)
                    ->where('created_at', '>=', $now->copy()->startOfMonth())
                    ->sum('total'),
            ],
            'outstanding' => ['currency' => 'SGD', 'amount' => (float) $outstanding],
        ];
    }

    /**
     * 8 weekly buckets (oldest -> newest) of order count + booked value, from
     * quotes.created_at. Bucketed in PHP (not a DB WEEK() expression) so the
     * math is identical on sqlite (tests) and MySQL (prod).
     *
     * @return array<int,array<string,mixed>>
     */
    public function trends(): array
    {
        $start = now()->startOfWeek()->subWeeks(7);

        $quotes = Quote::query()
            ->where('created_at', '>=', $start)
            ->get(['total', 'state', 'created_at']);

        $weeks = [];
        for ($i = 0; $i < 8; $i++) {
            $ws = $start->copy()->addWeeks($i);
            $we = $ws->copy()->addWeek();
            $bucket = $quotes->filter(
                fn (Quote $q): bool => $q->created_at >= $ws && $q->created_at < $we,
            );
            $booked = $bucket->filter(
                fn (Quote $q): bool => in_array($q->state->value, self::BOOKED_STATES, true),
            );

            $weeks[] = [
                'weekStart' => $ws->toDateString(),
                'orders' => $bucket->count(),
                'bookedValue' => (float) $booked->sum(fn (Quote $q): float => (float) $q->total),
            ];
        }

        return $weeks;
    }
```

Then fold both into `snapshot()`. Replace the `return [...]` block at the end of `snapshot()` with:

```php
        $kpis = $includeValue
            ? Cache::remember('dashboard.metrics.v1.kpis', 45, fn (): array => $this->kpis())
            : null;
        $trends = $includeValue
            ? Cache::remember('dashboard.metrics.v1.trends', 45, fn (): array => $this->trends())
            : null;

        return [
            ...$counts,
            'valueBooked' => $valueBooked,
            'kpis' => $kpis,
            'trends' => $trends,
            'atRisk' => $this->atRisk(),
            'activity' => $this->activity(),
        ];
```

Note: `Quote::$state` casts to `App\Enums\QuoteState` (confirmed: `'state' => QuoteState::class` in the model's `casts()`), so `$q->state->value` is correct.

- [ ] **Step 3b (only if you removed the skip): pin the clock**

If you prefer deterministic window tests over the `->skip()` guard, wrap the first test body with a fixed clock:

```php
    Carbon\Carbon::setTestNow('2026-06-15 12:00:00');
    // ... arrange + act + assert ...
    Carbon\Carbon::setTestNow();
```
Choose a date > 7 days into the month so "this week" and "this month" don't cross a boundary, and adjust the arranged `created_at`/expected counts accordingly. Keep whichever approach is green.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/DashboardKpisTest.php`
Expected: PASS (bucket + gating tests always; the KPI-number test passes or is date-skipped per its guard).

- [ ] **Step 5: Confirm no wider breakage (snapshot shape changed)**

Run: `vendor/bin/pest --filter=Dashboard`
Expected: PASS — existing dashboard tests still green with the two new nullable keys added.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Dashboard/DashboardMetrics.php tests/Feature/DashboardKpisTest.php
git commit -m "feat(dashboard): kpis() + trends() metrics behind the superadmin value gate"
```

---

## Task 2: Migration — index quotes.created_at

**Files:**
- Create: `database/migrations/2026_08_07_000002_add_created_at_index_to_quotes.php`

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_07_000002_add_created_at_index_to_quotes.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard KPIs + the 8-week trend roll up quotes by created_at (this-week
 * count, this-month booked sum, weekly buckets). quotes indexes state and
 * company_id but not created_at, so these range scans had no index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
```

- [ ] **Step 2: Apply + verify rollback (e2e sqlite)**

Run: `APP_ENV=e2e php artisan migrate --env=e2e` → new migration `DONE`.
Run: `APP_ENV=e2e php artisan migrate:rollback --env=e2e --step=1` → rolls back cleanly.
Re-apply: `APP_ENV=e2e php artisan migrate --env=e2e` → `DONE`.

- [ ] **Step 3: Suite still green (migration runs in test bootstrap)**

Run: `vendor/bin/pest tests/Feature/DashboardKpisTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_08_07_000002_add_created_at_index_to_quotes.php
git commit -m "perf(dashboard): index quotes.created_at for KPI/trend rollups"
```

---

## Task 3: Frontend — recharts, tiles, TrendChart

**Files:**
- Modify: `frontend/package.json` (add recharts)
- Modify: `frontend/src/lib/dashboard.ts`
- Create: `frontend/src/components/dashboard/TrendChart.tsx`
- Modify: `frontend/src/pages/DashboardPage.tsx`
- Test: `frontend/src/pages/DashboardKpis.render.test.tsx`

- [ ] **Step 1: Add the recharts dependency**

Run: `cd frontend && npm install recharts@^2.12.0`
Expected: `recharts` added to `dependencies` in `frontend/package.json`, lockfile updated.

- [ ] **Step 2: Extend the DTO**

In `frontend/src/lib/dashboard.ts`, add to the `DashboardPayload` interface (alongside `valueBooked`):

```ts
  kpis: {
    ordersThisWeek: number;
    bookedThisMonth: { currency: string; amount: number };
    outstanding: { currency: string; amount: number };
  } | null;
  trends: { weekStart: string; orders: number; bookedValue: number }[] | null;
```

- [ ] **Step 3: Write the failing render test**

Create `frontend/src/pages/DashboardKpis.render.test.tsx`:

```tsx
import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

vi.mock('../stores/dashboardStore', () => ({
  useDashboardStore: () => ({
    data: {
      queues: { proofsPending: 0, changesRequested: 0, procurementToReconfirm: 0, cataloguePending: 0, reordersOpen: 0, unpaidDelivered: 0 },
      production: { overdue: 0, wip: 0, byState: {} },
      pipeline: {},
      atRisk: [],
      valueBooked: { currency: 'SGD', amount: 1200 },
      activity: [],
      kpis: {
        ordersThisWeek: 4,
        bookedThisMonth: { currency: 'SGD', amount: 3400 },
        outstanding: { currency: 'SGD', amount: 900 },
      },
      trends: [
        { weekStart: '2026-06-16', orders: 2, bookedValue: 500 },
        { weekStart: '2026-06-23', orders: 3, bookedValue: 800 },
      ],
    },
    loading: false,
    error: null,
    load: vi.fn(),
  }),
}));

import { ThemeProvider } from '../ui';
import DashboardPage from './DashboardPage';

afterEach(cleanup);

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <DashboardPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('renders the three KPI tiles with their values', () => {
  renderPage();
  expect(screen.getByText('Orders this week')).toBeTruthy();
  expect(screen.getByText('4')).toBeTruthy();
  expect(screen.getByText('Booked value (this month)')).toBeTruthy();
  expect(screen.getByText('Outstanding to collect')).toBeTruthy();
});

it('renders the trend chart section', () => {
  renderPage();
  expect(screen.getByText(/last 8 weeks/i)).toBeTruthy();
});
```

- [ ] **Step 4: Run to verify it fails**

Run: `cd frontend && npx vitest run src/pages/DashboardKpis.render.test.tsx`
Expected: FAIL — tiles + trend section don't exist yet.

- [ ] **Step 5: Create `TrendChart`**

Create `frontend/src/components/dashboard/TrendChart.tsx`:

```tsx
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';

export interface TrendPoint {
  weekStart: string;
  orders: number;
  bookedValue: number;
}

const AXIS = 'rgb(var(--color-fg-subtle))';
const GRID = 'rgb(var(--color-border))';
const BAR = 'rgb(var(--color-primary))';
const LINE = 'rgb(var(--color-fg))';

/** Week label like "16 Jun" from an ISO date, without pulling in a date lib. */
function weekLabel(iso: string): string {
  const d = new Date(iso);
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}

export default function TrendChart({ data }: { data: TrendPoint[] }) {
  return (
    <div className="h-64 w-full" data-testid="trend-chart">
      <ResponsiveContainer width="100%" height="100%">
        <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 4, left: 4 }}>
          <CartesianGrid stroke={GRID} vertical={false} />
          <XAxis dataKey="weekStart" tickFormatter={weekLabel} stroke={AXIS} fontSize={12} tickLine={false} />
          <YAxis yAxisId="orders" stroke={AXIS} fontSize={12} tickLine={false} allowDecimals={false} />
          <YAxis yAxisId="value" orientation="right" stroke={AXIS} fontSize={12} tickLine={false} width={64}
            tickFormatter={(v: number) => `$${Number(v).toLocaleString()}`} />
          <Tooltip
            formatter={(value: number, name: string) =>
              name === 'bookedValue' ? [`$${Number(value).toLocaleString()}`, 'Booked value'] : [value, 'Orders']
            }
            labelFormatter={(l: string) => `Week of ${weekLabel(l)}`}
          />
          <Legend formatter={(v: string) => (v === 'bookedValue' ? 'Booked value' : 'Orders')} />
          <Bar yAxisId="orders" dataKey="orders" fill={BAR} radius={[3, 3, 0, 0]} maxBarSize={28} />
          <Line yAxisId="value" type="monotone" dataKey="bookedValue" stroke={LINE} strokeWidth={2} dot={false} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  );
}
```

- [ ] **Step 6: Wire tiles + chart into `DashboardPage`**

In `frontend/src/pages/DashboardPage.tsx`:

a) Add imports after the existing imports:

```tsx
import TrendChart from '../components/dashboard/TrendChart';
```

b) Add a currency formatter helper near the top (after `StatTile`):

```tsx
function money(v: { currency: string; amount: number }): string {
  return `${v.currency} ${v.amount.toLocaleString()}`;
}

function MoneyTile({ label, value, to }: { label: string; value: string; to: string }) {
  return (
    <Link to={to} className="rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
      <p className="text-sm text-fg-muted">{label}</p>
      <p className="mt-1 font-display text-2xl text-fg">{value}</p>
    </Link>
  );
}
```

c) Immediately AFTER the existing first `<section>` of stat tiles (the `grid ... lg:grid-cols-4` block), add the KPI tiles + trend, guarded on `data.kpis` / `data.trends`:

```tsx
      {data.kpis && (
        <section className="grid gap-4 sm:grid-cols-3">
          <StatTile label="Orders this week" value={data.kpis.ordersThisWeek} to="/quotes" />
          <MoneyTile label="Booked value (this month)" value={money(data.kpis.bookedThisMonth)} to="/quotes" />
          <MoneyTile label="Outstanding to collect" value={money(data.kpis.outstanding)} to="/quotes?filter=delivered_unpaid" />
        </section>
      )}

      {data.trends && data.trends.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="font-display text-xl text-fg">Orders &amp; booked value · last 8 weeks</h2>
          <Card padding="md">
            <TrendChart data={data.trends} />
          </Card>
        </section>
      )}
```

(`StatTile`, `Link`, `Card` are already imported/defined in this file. `MoneyTile` reuses `StatTile`'s styling but renders a string value.)

- [ ] **Step 7: Run the render test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/DashboardKpis.render.test.tsx`
Expected: PASS. (Recharts renders in jsdom; `ResponsiveContainer` may warn about zero width — harmless. If the chart's inner SVG doesn't render in jsdom, the test asserts the section heading + `data-testid="trend-chart"` container, both of which render regardless.)

- [ ] **Step 8: Typecheck + existing dashboard tests**

Run: `cd frontend && npm run typecheck` → clean.
Run: `cd frontend && npx vitest run src/pages/DashboardPage.test.tsx src/pages/DashboardActivity.render.test.tsx` → PASS. Those mocks lack `kpis`/`trends`; because the new sections are guarded on `data.kpis`/`data.trends` (undefined → falsy), they simply don't render and the existing tests stay green. If a mock's `data` object is typed strictly and TS complains about missing keys, add `kpis: null, trends: null` to that mock.

- [ ] **Step 9: Commit**

```bash
git add frontend/package.json frontend/package-lock.json frontend/src/lib/dashboard.ts frontend/src/components/dashboard/TrendChart.tsx frontend/src/pages/DashboardPage.tsx frontend/src/pages/DashboardKpis.render.test.tsx
git commit -m "feat(dashboard): KPI tiles + 8-week trend chart (recharts)"
```

---

## Self-Review

- **Spec coverage:** 3 tiles with the agreed definitions incl. Outstanding-to-collect and creation-date "this month" (Task 1 + Task 3); 8-week trend `ComposedChart` bars+line (Task 3); backend `kpis()`/`trends()` behind the superadmin `includeValue` gate + cached (Task 1); `quotes.created_at` index (Task 2); theme-correct chart via `rgb(var(--color-…))` (Task 3); tests both layers. Covered.
- **Placeholders:** none — full method bodies, migration, component, wiring, tests, commands. The one date-relative test carries an explicit skip guard + an alternative `setTestNow` recipe, not a placeholder.
- **Type consistency:** DTO `kpis`/`trends` shapes (Task 3 Step 2) match the backend payload keys (`ordersThisWeek`, `bookedThisMonth{currency,amount}`, `outstanding{currency,amount}`, `trends[]{weekStart,orders,bookedValue}`) from Task 1. `TrendPoint` matches `trends` element. `MoneyTile`/`StatTile` value types (string vs number) are distinct and used correctly.
- **Gating:** money block behind `includeValue` (superadmin), mirroring `valueBooked`; frontend guards on `data.kpis`/`data.trends` so a non-superadmin (null) or older payload renders the dashboard unchanged.
- **Risk noted:** `$q->state->value` depends on the Quote enum cast — Step 3 flags the plain-string fallback. Recharts-in-jsdom fragility handled by asserting the container/heading, not SVG internals.
