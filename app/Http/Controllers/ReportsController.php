<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Models\Quote;
use App\Services\Reporting\ReportingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only business reports + CSV export. Gated by permission:reports.view on
 * the route. All aggregation lives in ReportingService.
 *
 * permission:reports.view only restricts staff_admin (see EnsurePermission's
 * docblock - it deliberately lets non-staff_admin users through so their own
 * tenancy checks decide). This is a staff-only console page, so - same as
 * DashboardController - a buyer must be explicitly rejected here.
 */
class ReportsController extends Controller
{
    public function __construct(private readonly ReportingService $reports) {}

    public function index(ReportRequest $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        [$from, $to] = $request->range();

        return response()->json([
            'revenueTrend' => $this->reports->revenueTrend($from, $to),
            'topProducts' => $this->reports->topProducts($from, $to),
            'repeatCustomerRate' => $this->reports->repeatCustomerRate($from, $to),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ]);
    }

    public function export(ReportRequest $request): StreamedResponse
    {
        abort_unless($request->user()->isStaff(), 403);

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
}
