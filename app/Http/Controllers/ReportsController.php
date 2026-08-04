<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportRequest;
use App\Services\Reporting\ReportingService;
use Illuminate\Http\JsonResponse;

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
}
