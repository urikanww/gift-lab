<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Invoice;
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
