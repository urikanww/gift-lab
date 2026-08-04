# Business Reporting Layer (Point 6) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only `/admin/reports` page — revenue trend (bookings + billed, net of GST), top products, lifetime repeat-customer rate, and a streamed CSV order export — behind a new sensitive `reports.view` permission.

**Architecture:** A `ReportingService` (sibling to `DashboardMetrics`) exposes three range-scoped aggregates; a `ReportsController` returns them as JSON and streams the CSV. A `ReportRequest` validates/resolves the date range (default last 90 days). Frontend adds a permission-gated page with a range control, tables, an inline-SVG bar chart (no new dep), and a Download-CSV link.

**Tech Stack:** Laravel 11 (Pest), React + TS (Vitest), Zustand-style fetch, react-router.

**Spec:** `docs/superpowers/specs/2026-08-04-reporting-layer-design.md`
**Branch:** `feat/reporting-layer` (already created).

**Standing context:**
- Both suites green at branch point (backend 1143). Keep them green.
- **SQLite in tests / MySQL in prod.** `DATE_FORMAT` (MySQL) vs `strftime` (SQLite) diverge — so the month grouping in `revenueTrend` is done in PHP, NOT a raw SQL date function. Do not "optimize" it back into `selectRaw('DATE_FORMAT...')`.
- Order definition (used everywhere): a `Quote` with `accepted_at` not null AND `state != 'CANCELLED'`.
- Net revenue = amount − GST. Quote: `total - gst_amount`. Invoice: `amount - gst_amount`.
- Quote→invoices relation is `purchaseOrders(): HasMany<Invoice>` (no singular `invoice`). `line_items.quote_id`, `.product_id`, `.qty`, `.unit_price` all exist. Quote has `company` (belongsTo), `total`, `subtotal`, `gst_amount`, `currency`, `accepted_at`. Invoice has `invoice_ref`, `issued_at` (datetime cast), `amount`, `gst_amount`, `amount_paid`, `payment_state` (enum), `currency`.
- Permission model: `App\Support\Permissions::CATALOG` (section→actions) + `SENSITIVE_SECTIONS`; routes gate via `->middleware('permission:section.action')`; frontend routes via `<ProtectedRoute permission="...">`; staff nav is a `NAV` array of `{to,label,permission}` in `StaffLayout.tsx`.

---

## File Structure

**Backend (new):** `app/Services/Reporting/ReportingService.php`, `app/Http/Controllers/ReportsController.php`, `app/Http/Requests/ReportRequest.php`, `tests/Feature/ReportingTest.php`.
**Backend (modified):** `app/Support/Permissions.php`, `routes/api.php`.
**Frontend (new):** `frontend/src/lib/reports.ts`, `frontend/src/pages/ReportsPage.tsx`, `frontend/src/pages/ReportsPage.test.tsx`.
**Frontend (modified):** `frontend/src/App.tsx`, `frontend/src/components/StaffLayout.tsx`.

---

## Task 1: `reports` permission

**Files:** Modify `app/Support/Permissions.php`; Test `tests/Feature/ReportingTest.php`.

- [ ] **Step 1: Failing test.** Create `tests/Feature/ReportingTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\Permissions;

it('registers a sensitive reports.view permission', function (): void {
    expect(Permissions::all())->toContain('reports.view')
        ->and(Permissions::SENSITIVE_SECTIONS)->toContain('reports')
        ->and(Permissions::defaults())->not->toContain('reports.view');
});
```

- [ ] **Step 2:** `php artisan test --filter=ReportingTest` → FAIL.

- [ ] **Step 3:** In `app/Support/Permissions.php`, add a `reports` entry to the `CATALOG` array (after `courier`, before the sensitive `pricing` block):

```php
        'reports' => [
            'label' => 'Reports',
            'actions' => [
                'view' => 'View business reports & exports',
            ],
        ],
```

And add `'reports'` to `SENSITIVE_SECTIONS`:

```php
    public const SENSITIVE_SECTIONS = ['pricing', 'users', 'reports'];
```

- [ ] **Step 4:** `php artisan test --filter=ReportingTest` → PASS.

- [ ] **Step 5: Commit:**

```bash
git add app/Support/Permissions.php tests/Feature/ReportingTest.php
git commit -m "feat(reports): sensitive reports.view permission"
```

---

## Task 2: `ReportingService::revenueTrend`

**Files:** Create `app/Services/Reporting/ReportingService.php`; Test append.

- [ ] **Step 1: Failing test.** Append to `tests/Feature/ReportingTest.php` (add `use` lines):

```php
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Reporting\ReportingService;
use Illuminate\Support\Carbon;

it('builds a two-series revenue trend, net of GST, zero-filled', function (): void {
    $company = Company::factory()->create();

    // Booked in June (accepted), net = 100.
    Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'ACCEPTED',
        'accepted_at' => Carbon::parse('2026-06-10'), 'total' => 109, 'gst_amount' => 9,
    ]);
    // Cancelled in June — must NOT count as bookings.
    Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'CANCELLED',
        'accepted_at' => Carbon::parse('2026-06-11'), 'total' => 500, 'gst_amount' => 41.28,
    ]);
    // Billed in July: an invoice, net = 200.
    $q = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'INVOICED',
        'accepted_at' => Carbon::parse('2026-07-02'), 'total' => 218, 'gst_amount' => 18,
    ]);
    Invoice::factory()->create([
        'quote_id' => $q->id, 'issued_at' => Carbon::parse('2026-07-05'),
        'amount' => 218, 'gst_amount' => 18, 'payment_state' => 'UNPAID',
    ]);

    $trend = app(ReportingService::class)->revenueTrend(
        Carbon::parse('2026-06-01'), Carbon::parse('2026-07-31')
    );

    // Two months, in order, zero-filled where no activity.
    expect($trend)->toHaveCount(2);
    $june = collect($trend)->firstWhere('month', '2026-06');
    $july = collect($trend)->firstWhere('month', '2026-07');
    expect($june['bookings'])->toBe(100.0)   // cancelled excluded
        ->and($june['billed'])->toBe(0.0)
        ->and($july['bookings'])->toBe(200.0) // 218 - 18
        ->and($july['billed'])->toBe(200.0);
});
```

If `Invoice::factory()` doesn't exist, check `database/factories/` and use the real factory (or `Invoice::create([...])` with the required columns) — do not invent a factory API.

- [ ] **Step 2:** `php artisan test --filter=ReportingTest` → FAIL (service missing).

- [ ] **Step 3:** Create `app/Services/Reporting/ReportingService.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Quote;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Read-only business reports over a date range. Mirrors DashboardMetrics'
 * discipline: index-backed aggregates, no N+1. Month grouping is done in PHP
 * (not a SQL date function) because MySQL DATE_FORMAT and SQLite strftime
 * diverge and this project runs SQLite in tests / MySQL in prod.
 *
 * "Order" = a quote with accepted_at set and state != CANCELLED.
 * Revenue is net of GST (a remittable liability, not income).
 */
class ReportingService
{
    /**
     * Two monthly series over [from, to], zero-filled for months with no
     * activity: bookings (order value at acceptance) and billed (invoiced value).
     *
     * @return array<int, array{month: string, bookings: float, billed: float}>
     */
    public function revenueTrend(CarbonInterface $from, CarbonInterface $to): array
    {
        $months = $this->monthBuckets($from, $to);

        $bookings = Quote::query()
            ->whereNotNull('accepted_at')
            ->where('state', '!=', 'CANCELLED')
            ->whereBetween('accepted_at', [$from, $to])
            ->get(['accepted_at', 'total', 'gst_amount']);

        foreach ($bookings as $q) {
            $key = Carbon::parse((string) $q->accepted_at)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['bookings'] += (float) $q->total - (float) $q->gst_amount;
            }
        }

        $billed = Invoice::query()
            ->where('payment_state', '!=', 'VOID')
            ->whereNotNull('issued_at')
            ->whereBetween('issued_at', [$from, $to])
            ->get(['issued_at', 'amount', 'gst_amount']);

        foreach ($billed as $inv) {
            $key = Carbon::parse((string) $inv->issued_at)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['billed'] += (float) $inv->amount - (float) $inv->gst_amount;
            }
        }

        $out = [];
        foreach ($months as $month => $v) {
            $out[] = [
                'month' => $month,
                'bookings' => round($v['bookings'], 2),
                'billed' => round($v['billed'], 2),
            ];
        }

        return $out;
    }

    /**
     * Zero-filled month buckets from the start of $from's month to the start of
     * $to's month, keyed 'Y-m' in ascending order.
     *
     * @return array<string, array{bookings: float, billed: float}>
     */
    private function monthBuckets(CarbonInterface $from, CarbonInterface $to): array
    {
        $buckets = [];
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();
        while ($cursor <= $end) {
            $buckets[$cursor->format('Y-m')] = ['bookings' => 0.0, 'billed' => 0.0];
            $cursor = $cursor->addMonth();
        }

        return $buckets;
    }
}
```

- [ ] **Step 4:** `php artisan test --filter=ReportingTest` → PASS.

- [ ] **Step 5: Commit:**

```bash
git add app/Services/Reporting/ReportingService.php tests/Feature/ReportingTest.php
git commit -m "feat(reports): revenue trend (bookings + billed, net of GST)"
```

---

## Task 3: `topProducts` + `repeatCustomerRate`

**Files:** Modify `app/Services/Reporting/ReportingService.php`; Test append.

- [ ] **Step 1: Failing tests.** Append (reuse the imports from Task 2; add `use App\Models\Product;`, `use App\Models\Variant;`, `use App\Models\LineItem;`):

```php
it('ranks top products by net goods revenue over the range', function (): void {
    $company = Company::factory()->create();
    $mug = Product::factory()->create(['name' => 'Mug']);
    $pen = Product::factory()->create(['name' => 'Pen']);

    $order = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'ACCEPTED',
        'accepted_at' => Carbon::parse('2026-07-10'),
    ]);
    LineItem::factory()->create(['quote_id' => $order->id, 'product_id' => $mug->id, 'qty' => 10, 'unit_price' => 5]); // 50
    LineItem::factory()->create(['quote_id' => $order->id, 'product_id' => $pen->id, 'qty' => 100, 'unit_price' => 2]); // 200

    $top = app(ReportingService::class)->topProducts(
        Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31')
    );

    expect($top[0]['name'])->toBe('Pen')
        ->and($top[0]['revenue'])->toBe(200.0)
        ->and($top[0]['units'])->toBe(100)
        ->and($top[1]['name'])->toBe('Mug');
});

it('computes lifetime repeat-customer rate among range-active companies', function (): void {
    $repeat = Company::factory()->create();
    $once = Company::factory()->create();

    // Repeat company: 2 lifetime orders, one of them in range.
    Quote::factory()->create(['company_id' => $repeat->id, 'state' => 'ACCEPTED', 'accepted_at' => Carbon::parse('2026-01-05')]);
    Quote::factory()->create(['company_id' => $repeat->id, 'state' => 'ACCEPTED', 'accepted_at' => Carbon::parse('2026-07-10')]);
    // One-time company: single order, in range.
    Quote::factory()->create(['company_id' => $once->id, 'state' => 'ACCEPTED', 'accepted_at' => Carbon::parse('2026-07-12')]);

    $r = app(ReportingService::class)->repeatCustomerRate(
        Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31')
    );

    expect($r['activeCompanies'])->toBe(2)
        ->and($r['repeatCompanies'])->toBe(1)
        ->and($r['rate'])->toBe(0.5);
});

it('reports a zero repeat rate when no companies are active in range', function (): void {
    $r = app(ReportingService::class)->repeatCustomerRate(
        Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31')
    );

    expect($r['activeCompanies'])->toBe(0)->and($r['rate'])->toBe(0.0);
});
```

If `LineItem::factory()` requires extra non-null columns (e.g. `line_state`), set them in the `create([...])` calls to satisfy the schema — check `database/factories/LineItemFactory.php` first.

- [ ] **Step 2:** `php artisan test --filter=ReportingTest` → the 3 new FAIL.

- [ ] **Step 3:** Add both methods to `ReportingService`:

```php
    /**
     * Top products by net goods revenue (unit_price * qty; unit_price is
     * pre-GST, so this is net and excludes the flat customization/setup fee,
     * which is not product revenue) over orders accepted in [from, to].
     *
     * @return array<int, array{productId: int, name: ?string, units: int, revenue: float}>
     */
    public function topProducts(CarbonInterface $from, CarbonInterface $to, int $limit = 10): array
    {
        return LineItem::query()
            ->join('quotes', 'quotes.id', '=', 'line_items.quote_id')
            ->leftJoin('products', 'products.id', '=', 'line_items.product_id')
            ->whereNotNull('quotes.accepted_at')
            ->where('quotes.state', '!=', 'CANCELLED')
            ->whereBetween('quotes.accepted_at', [$from, $to])
            ->groupBy('line_items.product_id', 'products.name')
            ->selectRaw('line_items.product_id as product_id, products.name as name, '
                .'SUM(line_items.qty) as units, '
                .'SUM(line_items.unit_price * line_items.qty) as revenue')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r): array => [
                'productId' => (int) $r->product_id,
                'name' => $r->name,
                'units' => (int) $r->units,
                'revenue' => round((float) $r->revenue, 2),
            ])
            ->all();
    }

    /**
     * Of companies active in [from, to] (>=1 order accepted in range), the share
     * whose LIFETIME order count (same order definition, all-time) is >= 2.
     *
     * @return array{activeCompanies: int, repeatCompanies: int, rate: float}
     */
    public function repeatCustomerRate(CarbonInterface $from, CarbonInterface $to): array
    {
        $activeIds = Quote::query()
            ->whereNotNull('accepted_at')
            ->where('state', '!=', 'CANCELLED')
            ->whereBetween('accepted_at', [$from, $to])
            ->distinct()
            ->pluck('company_id')
            ->all();

        $active = count($activeIds);
        if ($active === 0) {
            return ['activeCompanies' => 0, 'repeatCompanies' => 0, 'rate' => 0.0];
        }

        $repeat = Quote::query()
            ->whereNotNull('accepted_at')
            ->where('state', '!=', 'CANCELLED')
            ->whereIn('company_id', $activeIds)
            ->groupBy('company_id')
            ->havingRaw('COUNT(*) >= 2')
            ->pluck('company_id')
            ->count();

        return [
            'activeCompanies' => $active,
            'repeatCompanies' => $repeat,
            'rate' => round($repeat / $active, 4),
        ];
    }
```

- [ ] **Step 4:** `php artisan test --filter=ReportingTest` → PASS.

- [ ] **Step 5: Commit:**

```bash
git add app/Services/Reporting/ReportingService.php tests/Feature/ReportingTest.php
git commit -m "feat(reports): top products + lifetime repeat-customer rate"
```

---

## Task 4: `ReportRequest` + `ReportsController::index` + JSON route

**Files:** Create `app/Http/Requests/ReportRequest.php`, `app/Http/Controllers/ReportsController.php`; Modify `routes/api.php`; Test append.

- [ ] **Step 1: Failing tests.** Append (add `use App\Models\User;`, `use Laravel\Sanctum\Sanctum;`):

```php
it('returns the three reports as JSON to a superadmin', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));

    $this->getJson('/api/admin/reports?from=2026-06-01&to=2026-07-31')
        ->assertOk()
        ->assertJsonStructure([
            'revenueTrend' => [['month', 'bookings', 'billed']],
            'topProducts',
            'repeatCustomerRate' => ['activeCompanies', 'repeatCompanies', 'rate'],
            'range' => ['from', 'to'],
        ]);
});

it('rejects an unpermitted staff_admin from the reports endpoint', function (): void {
    // staff_admin with no explicit grant: reports is sensitive, not in defaults.
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson('/api/admin/reports')->assertForbidden();
});

it('rejects a buyer from the reports endpoint', function (): void {
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['role' => 'buyer', 'company_id' => $company->id]));

    $this->getJson('/api/admin/reports')->assertForbidden();
});

it('422s on an inverted date range', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));

    $this->getJson('/api/admin/reports?from=2026-07-31&to=2026-06-01')
        ->assertStatus(422)->assertJsonValidationErrors('to');
});
```

- [ ] **Step 2:** `php artisan test --filter=ReportingTest` → the 4 new FAIL (route 404).

- [ ] **Step 3:** Create `app/Http/Requests/ReportRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Validates + resolves the reporting date range. Defaults to the last 90 days
 * when unset. Authorization is enforced by the route's permission middleware.
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * Resolved [from, to] as day-bounded Carbon instances; defaults last 90 days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function range(): array
    {
        $to = $this->filled('to') ? Carbon::parse($this->string('to')->toString()) : Carbon::now();
        $from = $this->filled('from') ? Carbon::parse($this->string('from')->toString()) : (clone $to)->subDays(90);

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
```

- [ ] **Step 4:** Create `app/Http/Controllers/ReportsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Services\Reporting\ReportingService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only business reports + CSV export. Gated by permission:reports.view on
 * the route. All aggregation lives in ReportingService.
 */
class ReportsController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    public function index(ReportRequest $request): JsonResponse
    {
        [$from, $to] = $request->range();

        return response()->json([
            'revenueTrend' => $this->reports->revenueTrend($from, $to),
            'topProducts' => $this->reports->topProducts($from, $to),
            'repeatCustomerRate' => $this->reports->repeatCustomerRate($from, $to),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }
}
```

- [ ] **Step 5:** Add the route to `routes/api.php`, beside `/admin/dashboard` (line ~328). Add `use App\Http\Controllers\ReportsController;` with the other controller imports if not auto-imported:

```php
    Route::get('/admin/reports', [ReportsController::class, 'index'])->middleware('permission:reports.view');
```

- [ ] **Step 6:** `php artisan test --filter=ReportingTest` → PASS (all).

- [ ] **Step 7: Commit:**

```bash
git add app/Http/Requests/ReportRequest.php app/Http/Controllers/ReportsController.php routes/api.php tests/Feature/ReportingTest.php
git commit -m "feat(reports): reports JSON endpoint (permission-gated)"
```

---

## Task 5: CSV export

**Files:** Modify `app/Http/Controllers/ReportsController.php`; Modify `routes/api.php`; Test append.

- [ ] **Step 0: Confirm the invoice relation.** `grep -n "function purchaseOrders" app/Models/Quote.php` — it's `purchaseOrders(): HasMany<Invoice>`. Use it (a quote may have >1 invoice historically; take the most recent by `issued_at`).

- [ ] **Step 1: Failing test.** Append:

```php
it('streams a CSV of orders in range with a GST column and both dates', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));
    $company = Company::factory()->create(['name' => 'Acme Pte Ltd']);
    $q = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'INVOICED',
        'accepted_at' => Carbon::parse('2026-07-10'),
        'subtotal' => 200, 'gst_amount' => 18, 'total' => 218, 'currency' => 'SGD',
    ]);
    Invoice::factory()->create([
        'quote_id' => $q->id, 'invoice_ref' => 'INV-1001',
        'issued_at' => Carbon::parse('2026-07-11'), 'amount' => 218, 'gst_amount' => 18,
        'amount_paid' => 0, 'payment_state' => 'UNPAID',
    ]);

    $res = $this->get('/api/admin/reports/export?from=2026-07-01&to=2026-07-31');

    $res->assertOk();
    expect($res->headers->get('content-type'))->toContain('text/csv');

    $csv = $res->streamedContent();
    expect($csv)->toContain('reference,company,accepted_at,invoice_ref,issued_at,state,payment_state,subtotal,gst_amount,total,amount_paid,currency')
        ->and($csv)->toContain('Acme Pte Ltd')
        ->and($csv)->toContain('INV-1001')
        ->and($csv)->toContain('2026-07-10')  // accepted date
        ->and($csv)->toContain('2026-07-11')  // issued date
        ->and($csv)->toContain('18');         // gst
});

it('blocks CSV export without reports.view', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->get('/api/admin/reports/export')->assertForbidden();
});
```

- [ ] **Step 2:** `php artisan test --filter=ReportingTest` → the 2 new FAIL.

- [ ] **Step 3:** Add `export()` to `ReportsController` (add `use App\Models\Quote;` and `use Symfony\Component\HttpFoundation\StreamedResponse;`):

```php
    public function export(ReportRequest $request): StreamedResponse
    {
        [$from, $to] = $request->range();
        $filename = "giftlab-orders-{$from->toDateString()}-{$to->toDateString()}.csv";

        return response()->streamDownload(function () use ($from, $to): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'reference', 'company', 'accepted_at', 'invoice_ref', 'issued_at',
                'state', 'payment_state', 'subtotal', 'gst_amount', 'total',
                'amount_paid', 'currency',
            ]);

            Quote::query()
                ->with(['company:id,name', 'purchaseOrders'])
                ->whereNotNull('accepted_at')
                ->where('state', '!=', 'CANCELLED')
                ->whereBetween('accepted_at', [$from, $to])
                ->orderBy('accepted_at')
                ->chunk(500, function ($quotes) use ($out): void {
                    foreach ($quotes as $q) {
                        // Most recent invoice for this order, if any.
                        $inv = $q->purchaseOrders->sortByDesc('issued_at')->first();
                        fputcsv($out, [
                            $q->reference,
                            $q->company?->name,
                            optional($q->accepted_at)->toDateString(),
                            $inv?->invoice_ref,
                            $inv?->issued_at?->toDateString(),
                            is_object($q->state) ? $q->state->value : $q->state,
                            $inv && is_object($inv->payment_state) ? $inv->payment_state->value : ($inv?->payment_state),
                            $q->subtotal,
                            $q->gst_amount,
                            $q->total,
                            $inv?->amount_paid,
                            $q->currency ?? 'SGD',
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
```

Note: `optional($q->accepted_at)->toDateString()` assumes `accepted_at` is a Carbon (cast). If Quote does NOT cast `accepted_at` to datetime, wrap with `Carbon::parse` as the service does. Check `Quote::casts()` first; if uncast, use `$q->accepted_at ? \Illuminate\Support\Carbon::parse((string) $q->accepted_at)->toDateString() : null`.

- [ ] **Step 4:** Add the route to `routes/api.php` beside the index route:

```php
    Route::get('/admin/reports/export', [ReportsController::class, 'export'])->middleware('permission:reports.view');
```

- [ ] **Step 5:** `php artisan test --filter=ReportingTest` → PASS (all).

- [ ] **Step 6: Commit:**

```bash
git add app/Http/Controllers/ReportsController.php routes/api.php tests/Feature/ReportingTest.php
git commit -m "feat(reports): streamed CSV order export"
```

---

## Task 6: Frontend — reports page, route, nav

**Files:** Create `frontend/src/lib/reports.ts`, `frontend/src/pages/ReportsPage.tsx`, `frontend/src/pages/ReportsPage.test.tsx`; Modify `frontend/src/App.tsx`, `frontend/src/components/StaffLayout.tsx`.

- [ ] **Step 1: API client.** Create `frontend/src/lib/reports.ts` (model the typed-fetch on `lib/dashboard.ts`):

```ts
import api from './api';

export interface RevenueMonth {
  month: string;
  bookings: number;
  billed: number;
}
export interface TopProduct {
  productId: number;
  name: string | null;
  units: number;
  revenue: number;
}
export interface ReportsPayload {
  revenueTrend: RevenueMonth[];
  topProducts: TopProduct[];
  repeatCustomerRate: { activeCompanies: number; repeatCompanies: number; rate: number };
  range: { from: string; to: string };
}

export async function fetchReports(from: string, to: string): Promise<ReportsPayload> {
  const { data } = await api.get<ReportsPayload>('/admin/reports', { params: { from, to } });
  return data;
}

/** Absolute URL for the CSV export (a plain anchor download; Sanctum cookie authenticates). */
export function reportsExportUrl(from: string, to: string): string {
  const base = (api.defaults.baseURL ?? '').replace(/\/$/, '');
  const qs = new URLSearchParams({ from, to }).toString();
  return `${base}/admin/reports/export?${qs}`;
}
```

- [ ] **Step 2: Failing page test.** Create `frontend/src/pages/ReportsPage.test.tsx`:

```tsx
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import ReportsPage from './ReportsPage';
import * as reports from '../lib/reports';

describe('ReportsPage', () => {
  beforeEach(() => {
    vi.spyOn(reports, 'fetchReports').mockResolvedValue({
      revenueTrend: [{ month: '2026-07', bookings: 200, billed: 180 }],
      topProducts: [{ productId: 1, name: 'Pen', units: 100, revenue: 200 }],
      repeatCustomerRate: { activeCompanies: 4, repeatCompanies: 1, rate: 0.25 },
      range: { from: '2026-05-01', to: '2026-07-31' },
    });
  });

  it('renders the trend, top products, repeat rate, and a CSV link', async () => {
    render(<MemoryRouter><ReportsPage /></MemoryRouter>);

    expect(await screen.findByText('Pen')).toBeInTheDocument();
    expect(screen.getByText(/25%/)).toBeInTheDocument();             // repeat rate
    expect(screen.getByText('2026-07')).toBeInTheDocument();          // trend month
    expect(screen.getByRole('link', { name: /download csv/i })).toBeInTheDocument();
    await waitFor(() => expect(reports.fetchReports).toHaveBeenCalled());
  });
});
```

- [ ] **Step 3:** `cd frontend && npx vitest run src/pages/ReportsPage.test.tsx` → FAIL (module missing).

- [ ] **Step 4:** Create `frontend/src/pages/ReportsPage.tsx`. Range presets computed client-side; default last 90 days. Inline-SVG bars for the trend (no new dep):

```tsx
import { useEffect, useMemo, useState } from 'react';
import { Card, Skeleton } from '../ui';
import { ErrorState } from '../components/ui/States';
import { fetchReports, reportsExportUrl, type ReportsPayload } from '../lib/reports';

function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

const PRESETS: Record<string, () => { from: string; to: string }> = {
  'Last 90 days': () => {
    const to = new Date();
    const from = new Date();
    from.setDate(from.getDate() - 90);
    return { from: isoDate(from), to: isoDate(to) };
  },
  'This month': () => {
    const now = new Date();
    return { from: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)), to: isoDate(now) };
  },
  'Year to date': () => {
    const now = new Date();
    return { from: isoDate(new Date(now.getFullYear(), 0, 1)), to: isoDate(now) };
  },
};

export default function ReportsPage() {
  const [preset, setPreset] = useState<string>('Last 90 days');
  const range = useMemo(() => PRESETS[preset](), [preset]);
  const [data, setData] = useState<ReportsPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    setLoading(true);
    setError(null);
    fetchReports(range.from, range.to)
      .then((d) => live && setData(d))
      .catch(() => live && setError('Could not load reports.'))
      .finally(() => live && setLoading(false));
    return () => { live = false; };
  }, [range.from, range.to]);

  const maxRevenue = useMemo(
    () => Math.max(1, ...(data?.revenueTrend.flatMap((m) => [m.bookings, m.billed]) ?? [])),
    [data],
  );

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="font-display text-3xl text-fg">Reports</h1>
        <div className="flex items-center gap-2">
          <select
            aria-label="Date range"
            value={preset}
            onChange={(e) => setPreset(e.target.value)}
            className="rounded-md border border-border bg-surface px-3 py-1.5 text-sm text-fg"
          >
            {Object.keys(PRESETS).map((p) => <option key={p} value={p}>{p}</option>)}
          </select>
          <a
            href={reportsExportUrl(range.from, range.to)}
            className="rounded-md border border-border bg-surface px-3 py-1.5 text-sm font-medium text-fg hover:border-primary/50"
          >
            Download CSV
          </a>
        </div>
      </div>

      {loading && !data ? (
        <div className="grid gap-4 sm:grid-cols-3">{Array.from({ length: 3 }).map((_, i) => <Skeleton key={i} height="8rem" />)}</div>
      ) : error && !data ? (
        <ErrorState message={error} />
      ) : data ? (
        <>
          <Card padding="lg">
            <h2 className="font-display text-xl text-fg">Revenue (net of GST)</h2>
            <p className="mt-1 text-sm text-fg-muted">Bookings (accepted) vs Billed (invoiced), by month.</p>
            <table className="mt-4 w-full text-sm">
              <thead>
                <tr className="text-left text-fg-subtle">
                  <th className="py-1">Month</th><th className="py-1 text-right">Bookings</th><th className="py-1 text-right">Billed</th>
                </tr>
              </thead>
              <tbody>
                {data.revenueTrend.map((m) => (
                  <tr key={m.month} className="border-t border-border">
                    <td className="py-1.5">{m.month}</td>
                    <td className="py-1.5 text-right tabular-nums">{m.bookings.toFixed(2)}</td>
                    <td className="py-1.5 text-right tabular-nums">{m.billed.toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
            {/* Lightweight inline bars (no chart dep). */}
            <div className="mt-4 flex items-end gap-3" aria-hidden="true">
              {data.revenueTrend.map((m) => (
                <div key={m.month} className="flex flex-1 flex-col items-center gap-1">
                  <div className="flex h-24 w-full items-end justify-center gap-0.5">
                    <div className="w-1/3 bg-primary/70" style={{ height: `${(m.bookings / maxRevenue) * 100}%` }} />
                    <div className="w-1/3 bg-accent-500/70" style={{ height: `${(m.billed / maxRevenue) * 100}%` }} />
                  </div>
                  <span className="text-2xs text-fg-subtle">{m.month.slice(5)}</span>
                </div>
              ))}
            </div>
          </Card>

          <Card padding="lg">
            <h2 className="font-display text-xl text-fg">Top products</h2>
            <table className="mt-4 w-full text-sm">
              <thead><tr className="text-left text-fg-subtle"><th className="py-1">Product</th><th className="py-1 text-right">Units</th><th className="py-1 text-right">Revenue</th></tr></thead>
              <tbody>
                {data.topProducts.map((p) => (
                  <tr key={p.productId} className="border-t border-border">
                    <td className="py-1.5">{p.name ?? `Product #${p.productId}`}</td>
                    <td className="py-1.5 text-right tabular-nums">{p.units}</td>
                    <td className="py-1.5 text-right tabular-nums">{p.revenue.toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          <Card padding="lg">
            <h2 className="font-display text-xl text-fg">Repeat customers</h2>
            <p className="mt-2 font-display text-3xl text-fg">{Math.round(data.repeatCustomerRate.rate * 100)}%</p>
            <p className="text-sm text-fg-muted">
              {data.repeatCustomerRate.repeatCompanies} of {data.repeatCustomerRate.activeCompanies} active companies have ordered 2+ times.
            </p>
          </Card>
        </>
      ) : null}
    </div>
  );
}
```

If `accent-500` isn't a defined token, use any existing second color token (check `tailwind.config`); the exact hue is cosmetic.

- [ ] **Step 5: Wire route + nav.** In `frontend/src/App.tsx`, add the lazy import beside the other admin pages and a guarded route inside the authenticated block (mirror `pricing-admin`):

```tsx
const ReportsPage = lazy(() => import('./pages/ReportsPage'));
```
```tsx
              <Route path="reports" element={<ProtectedRoute permission="reports.view"><ReportsPage /></ProtectedRoute>} />
```
In `frontend/src/components/StaffLayout.tsx`, add a nav entry to the `NAV` array (near Pricing):

```tsx
    { to: '/reports', label: 'Reports', permission: 'reports.view' },
```

- [ ] **Step 6:** `cd frontend && npx vitest run src/pages/ReportsPage.test.tsx && npx tsc --noEmit` → PASS + clean.

- [ ] **Step 7: Commit:**

```bash
git add frontend/src/lib/reports.ts frontend/src/pages/ReportsPage.tsx frontend/src/pages/ReportsPage.test.tsx frontend/src/App.tsx frontend/src/components/StaffLayout.tsx
git commit -m "feat(reports): /admin/reports page, route, and nav"
```

---

## Task 7: Full-suite verification

- [ ] **Step 1:** `php artisan test` — expect 0 failures (branch count + new ReportingTest cases).
- [ ] **Step 2:** `cd frontend && npx vitest run && npx tsc --noEmit` — all green, tsc clean.
- [ ] **Step 3:** Commit any fix (skip if none).

---

## Self-Review

- **Spec coverage:** permission (T1) ✓; revenue trend two-series net-of-GST zero-filled (T2) ✓; top products net goods revenue (T3) ✓; lifetime repeat rate among active + zero-guard (T3) ✓; JSON endpoint + range default + 403 gate + 422 (T4) ✓; streamed CSV both-dates+GST + gate (T5) ✓; page + range presets + bars + tables + CSV link + nav + guarded route (T6) ✓; full verify (T7) ✓.
- **Placeholder scan:** none — the two conditional notes (Invoice factory shape, `accepted_at` cast, `accent-500` token) are verify-then-adapt guidance with concrete fallbacks, not TODOs.
- **Type/name consistency:** `ReportingService::revenueTrend/topProducts/repeatCustomerRate`; payload keys `revenueTrend|topProducts|repeatCustomerRate|range`; `ReportRequest::range()`; permission `reports.view`; route `/admin/reports` + `/admin/reports/export`; frontend `fetchReports`/`reportsExportUrl`/`ReportsPayload` — consistent across backend, routes, and frontend.
