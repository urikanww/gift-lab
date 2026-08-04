<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Services\Reporting\ReportingService;
use App\Support\Permissions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

it('registers a sensitive reports.view permission', function (): void {
    expect(Permissions::all())->toContain('reports.view')
        ->and(Permissions::SENSITIVE_SECTIONS)->toContain('reports')
        ->and(Permissions::defaults())->not->toContain('reports.view');
});

it('builds a two-series revenue trend, net of GST, zero-filled', function (): void {
    $company = Company::factory()->create();

    Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'ACCEPTED',
        'accepted_at' => Carbon::parse('2026-06-10'), 'total' => 109, 'gst_amount' => 9,
    ]);
    Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'CANCELLED',
        'accepted_at' => Carbon::parse('2026-06-11'), 'total' => 500, 'gst_amount' => 41.28,
    ]);
    $q = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'INVOICED',
        'accepted_at' => Carbon::parse('2026-07-02'), 'total' => 218, 'gst_amount' => 18,
    ]);
    Invoice::create([
        'quote_id' => $q->id, 'po_ref' => 'PO-REPORT-1',
        'issued_at' => Carbon::parse('2026-07-05'),
        'amount' => 218, 'gst_amount' => 18, 'payment_state' => 'UNPAID',
    ]);

    $trend = app(ReportingService::class)->revenueTrend(
        Carbon::parse('2026-06-01'), Carbon::parse('2026-07-31')
    );

    expect($trend)->toHaveCount(2);
    $june = collect($trend)->firstWhere('month', '2026-06');
    $july = collect($trend)->firstWhere('month', '2026-07');
    expect($june['bookings'])->toBe(100.0)
        ->and($june['billed'])->toBe(0.0)
        ->and($july['bookings'])->toBe(200.0)
        ->and($july['billed'])->toBe(200.0);
});

it('zero-fills months with no activity, in ascending order', function (): void {
    $company = Company::factory()->create();
    // Only May has an order; June must still appear, zero-filled.
    Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'ACCEPTED',
        'accepted_at' => Carbon::parse('2026-05-15'), 'total' => 50, 'gst_amount' => 0,
    ]);

    $trend = app(ReportingService::class)->revenueTrend(
        Carbon::parse('2026-05-01'), Carbon::parse('2026-07-31')
    );

    expect(array_column($trend, 'month'))->toBe(['2026-05', '2026-06', '2026-07']);
    $june = collect($trend)->firstWhere('month', '2026-06');
    expect($june['bookings'])->toBe(0.0)->and($june['billed'])->toBe(0.0);
});

it('excludes VOID invoices from billed revenue', function (): void {
    $company = Company::factory()->create();
    $q = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'INVOICED',
        'accepted_at' => Carbon::parse('2026-07-02'), 'total' => 109, 'gst_amount' => 9,
    ]);
    Invoice::create([
        'quote_id' => $q->id, 'po_ref' => 'PO-VOID-1',
        'issued_at' => Carbon::parse('2026-07-06'),
        'amount' => 109, 'gst_amount' => 9, 'payment_state' => 'VOID',
    ]);

    $trend = app(ReportingService::class)->revenueTrend(
        Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31')
    );

    expect(collect($trend)->firstWhere('month', '2026-07')['billed'])->toBe(0.0);
});

it('ranks top products by net goods revenue over the range', function (): void {
    $company = Company::factory()->create();
    $mug = Product::factory()->create(['name' => 'Mug']);
    $pen = Product::factory()->create(['name' => 'Pen']);

    $order = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'ACCEPTED',
        'accepted_at' => Carbon::parse('2026-07-10'),
    ]);
    LineItem::factory()->create(['quote_id' => $order->id, 'product_id' => $mug->id, 'qty' => 10, 'unit_price' => 5]);
    LineItem::factory()->create(['quote_id' => $order->id, 'product_id' => $pen->id, 'qty' => 100, 'unit_price' => 2]);

    // Must NOT appear: a cancelled order, and an in-catalogue order outside the range.
    $ghost = Product::factory()->create(['name' => 'Ghost']);
    $cancelled = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'CANCELLED', 'accepted_at' => Carbon::parse('2026-07-09'),
    ]);
    LineItem::factory()->create(['quote_id' => $cancelled->id, 'product_id' => $ghost->id, 'qty' => 999, 'unit_price' => 9]);
    $outOfRange = Quote::factory()->create([
        'company_id' => $company->id, 'state' => 'ACCEPTED', 'accepted_at' => Carbon::parse('2026-05-01'),
    ]);
    LineItem::factory()->create(['quote_id' => $outOfRange->id, 'product_id' => $ghost->id, 'qty' => 999, 'unit_price' => 9]);

    // Soft-deleting a product must NOT drop its historical revenue (leftJoin +
    // restrictOnDelete): the report still names it.
    $mug->delete();

    $top = app(ReportingService::class)->topProducts(
        Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31')
    );

    expect($top)->toHaveCount(2)                       // Ghost (cancelled + out-of-range) excluded
        ->and(collect($top)->pluck('name'))->not->toContain('Ghost')
        ->and($top[0]['name'])->toBe('Pen')
        ->and($top[0]['revenue'])->toBe(200.0)
        ->and($top[0]['units'])->toBe(100)
        ->and($top[1]['name'])->toBe('Mug');            // soft-deleted, still shown
});

it('computes lifetime repeat-customer rate among range-active companies', function (): void {
    $repeat = Company::factory()->create();
    $once = Company::factory()->create();

    Quote::factory()->create(['company_id' => $repeat->id, 'state' => 'ACCEPTED', 'accepted_at' => Carbon::parse('2026-01-05')]);
    Quote::factory()->create(['company_id' => $repeat->id, 'state' => 'ACCEPTED', 'accepted_at' => Carbon::parse('2026-07-10')]);
    Quote::factory()->create(['company_id' => $once->id, 'state' => 'ACCEPTED', 'accepted_at' => Carbon::parse('2026-07-12')]);
    // A cancelled order in range must not make its company count as active.
    $cancelledCo = Company::factory()->create();
    Quote::factory()->create(['company_id' => $cancelledCo->id, 'state' => 'CANCELLED', 'accepted_at' => Carbon::parse('2026-07-13')]);

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

it('defaults to roughly the last 90 days when no range is given', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => 'superadmin']));

    $range = $this->getJson('/api/admin/reports')->assertOk()->json('range');
    $from = Carbon::parse($range['from']);
    $to = Carbon::parse($range['to']);

    // "to" is today; the window spans ~90 days (89-91 tolerates the day-bound edges).
    expect($to->toDateString())->toBe(Carbon::now()->toDateString())
        ->and($from->diffInDays($to))->toBeGreaterThanOrEqual(89)
        ->and($from->diffInDays($to))->toBeLessThanOrEqual(91);
});
