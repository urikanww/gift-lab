<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Quote;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
    /** Aggregates are historical, not point-in-time; a few minutes is plenty fresh. */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * @return array<int, array{month: string, bookings: float, billed: float}>
     *
     * Timezone assumption: $from/$to and the stored timestamps must share a
     * timezone (both UTC today, the app default). Month bucketing formats both
     * bounds and rows in whatever tz they carry; a caller that builds $from/$to
     * in a different tz than the DB stores (e.g. Asia/Singapore business-day
     * bounds against UTC timestamps) could mis-bucket a row near a month edge.
     * ReportRequest::range() must produce bounds in the app timezone.
     */
    public function revenueTrend(CarbonInterface $from, CarbonInterface $to): array
    {
        return Cache::remember(
            "reports.revenueTrend.{$from->timestamp}.{$to->timestamp}",
            self::CACHE_TTL_SECONDS,
            function () use ($from, $to): array {
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
        );
    }

    /**
     * Top products by net goods revenue (unit_price * qty; unit_price is
     * pre-GST, so this is net and excludes the flat customization/setup fee,
     * which is not product revenue) over orders accepted in [from, to].
     *
     * @return array<int, array{productId: int, name: ?string, units: int, revenue: float}>
     */
    public function topProducts(CarbonInterface $from, CarbonInterface $to, int $limit = 10): array
    {
        return Cache::remember(
            "reports.topProducts.{$from->timestamp}.{$to->timestamp}.{$limit}",
            self::CACHE_TTL_SECONDS,
            fn (): array => LineItem::query()
                ->join('quotes', 'quotes.id', '=', 'line_items.quote_id')
                // Raw join bypasses Product's SoftDeletingScope, so a soft-deleted
                // product keeps its historical revenue under its real name.
                // leftJoin (not join) also survives the theoretical missing-product
                // row, though restrictOnDelete on line_items.product_id prevents that.
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
                ->all(),
        );
    }

    /**
     * Of companies active in [from, to] (>=1 order accepted in range), the share
     * whose LIFETIME order count (same order definition, all-time) is >= 2.
     *
     * @return array{activeCompanies: int, repeatCompanies: int, rate: float}
     */
    public function repeatCustomerRate(CarbonInterface $from, CarbonInterface $to): array
    {
        return Cache::remember(
            "reports.repeatCustomerRate.{$from->timestamp}.{$to->timestamp}",
            self::CACHE_TTL_SECONDS,
            function () use ($from, $to): array {
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
        );
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
