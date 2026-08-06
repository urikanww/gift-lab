<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\LineItemResource;
use App\Models\LineItem;
use App\Models\Quote;
use App\Services\Procurement\BuyListQuery;
use App\Services\QuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The manual buy list: lines waiting to be bought for approved orders. Buying a
 * line raises the bill and advances it to the production floor (the old
 * automatic sourcing engine and reconfirm desk have been removed).
 */
class ProcurementController extends Controller
{
    public function __construct(
        private readonly QuoteService $quotes,
        private readonly BuyListQuery $buyList,
    ) {
    }

    /**
     * The manual buy list: every line waiting to be bought for an approved
     * order, in both groupings the page toggles between (grouped client-side).
     */
    public function buyList(): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        $lines = $this->buyList->lines()
            ->with(['product', 'quote'])
            ->get();

        return LineItemResource::collection($lines);
    }

    /**
     * Staff bought (or printed) a single line: raise the bill, advance it to the
     * floor. See QuoteService::markLineBought.
     */
    public function markBought(LineItem $lineItem): LineItemResource
    {
        $this->authorize('manageProduction', Quote::class);

        $this->quotes->markLineBought($lineItem);

        return new LineItemResource($lineItem->fresh()->load(['product', 'quote']));
    }

    /**
     * "Mark all bought" for one product across every eligible order (the grouped
     * buy-list view). Returns how many lines were advanced.
     */
    public function markProductBought(int $product): JsonResponse
    {
        $this->authorize('manageProduction', Quote::class);

        $count = $this->quotes->markProductBought($product);

        return response()->json(['marked' => $count]);
    }
}
