<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Quote;
use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Support\Carbon;

it('computes the three KPI numbers from existing data', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');

    Quote::factory()->create(['created_at' => now()->subDays(1), 'state' => 'DRAFT', 'total' => 100]);
    Quote::factory()->create(['created_at' => now()->subDays(3), 'state' => 'ACCEPTED', 'total' => 250]);
    Quote::factory()->create(['created_at' => now()->subDays(10), 'state' => 'ACCEPTED', 'total' => 999]); // >7d, still this month
    Quote::factory()->create(['created_at' => now()->startOfMonth(), 'state' => 'CONFIRMED', 'total' => 400]);

    // Invoices belong to a quote; bind both to ONE quote created outside the
    // week/month window so they don't inflate ordersThisWeek/bookedThisMonth.
    $oldQuote = Quote::factory()->create(['created_at' => now()->subMonths(2), 'state' => 'DRAFT', 'total' => 0]);
    Invoice::factory()->for($oldQuote, 'quote')->create(['payment_state' => 'PARTIAL', 'amount' => 500, 'amount_paid' => 200]); // owes 300
    Invoice::factory()->for($oldQuote, 'quote')->create(['payment_state' => 'UNPAID', 'amount' => 100, 'amount_paid' => null]);  // owes 100
    Invoice::factory()->for($oldQuote, 'quote')->create(['payment_state' => 'PAID', 'amount' => 800, 'amount_paid' => 800]);     // excluded
    Invoice::factory()->for($oldQuote, 'quote')->create(['payment_state' => 'VOID', 'amount' => 999, 'amount_paid' => null]);    // excluded

    $kpis = app(DashboardMetrics::class)->kpis();

    // ordersThisWeek: created within last 7d of 2026-06-15 => the subDays(1) and subDays(3) only.
    expect($kpis['ordersThisWeek'])->toBe(2)
        // bookedThisMonth: ACCEPTED@250 (3d) + ACCEPTED@999 (10d, same month) + CONFIRMED@400 (1st) = 1649; DRAFT excluded.
        ->and($kpis['bookedThisMonth']['amount'])->toBe(1649.0)
        ->and($kpis['outstanding']['amount'])->toBe(400.0);

    Carbon::setTestNow();
});

it('buckets the last 8 weeks with orders and booked value', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');

    Quote::factory()->create(['created_at' => now()->subWeeks(1), 'state' => 'ACCEPTED', 'total' => 100]);
    Quote::factory()->create(['created_at' => now()->subWeeks(1), 'state' => 'DRAFT', 'total' => 999]);
    Quote::factory()->create(['created_at' => now()->subWeeks(20), 'state' => 'ACCEPTED', 'total' => 500]); // outside window

    $trends = app(DashboardMetrics::class)->trends();

    expect($trends)->toHaveCount(8)
        ->and($trends[0])->toHaveKeys(['weekStart', 'orders', 'bookedValue'])
        ->and(collect($trends)->sum('orders'))->toBe(2)
        ->and(collect($trends)->sum('bookedValue'))->toBe(100.0);

    Carbon::setTestNow();
});

it('includes kpis+trends only for superadmin (includeValue), null otherwise', function (): void {
    $metrics = app(DashboardMetrics::class);

    $super = $metrics->snapshot(true);
    expect($super)->toHaveKeys(['kpis', 'trends'])
        ->and($super['kpis'])->not->toBeNull()
        ->and($super['trends'])->not->toBeNull();

    $staff = $metrics->snapshot(false);
    expect($staff['kpis'])->toBeNull()
        ->and($staff['trends'])->toBeNull();
});
