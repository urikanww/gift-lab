<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Reporting\ReportingService;
use App\Support\Permissions;
use Illuminate\Support\Carbon;

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
