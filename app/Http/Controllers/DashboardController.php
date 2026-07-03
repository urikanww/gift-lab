<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff console overview. Read-only aggregate snapshot; value-booked is
 * superadmin-only. All heavy lifting is in DashboardMetrics.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardMetrics $metrics) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        return response()->json(
            $this->metrics->snapshot($request->user()->isSuperadmin()),
        );
    }
}
