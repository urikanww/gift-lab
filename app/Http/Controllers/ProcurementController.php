<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LineItemState;
use App\Http\Requests\ReconfirmLineItemRequest;
use App\Http\Resources\LineItemResource;
use App\Models\LineItem;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Staff resolution of line items stuck in AWAITING_RECONFIRM after a failed
 * stock/price re-check. Amend re-procures; approve accepts as-is; drop removes
 * the line without killing the rest of the order (spec 5.2).
 */
class ProcurementController extends Controller
{
    public function __construct(private readonly QuoteService $quotes)
    {
    }

    /**
     * Every line currently awaiting a staff decision.
     *
     * The desk had no data source at all: it subscribed to a broadcast and
     * nothing else, so a blocked line was visible only to whoever happened to
     * have the page open at the instant it broke. Anyone arriving later — including
     * staff following the "Go to procurement desk" link placed on the order
     * precisely because a line was blocked — saw an empty desk.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        $lines = LineItem::query()
            ->where('line_state', LineItemState::AwaitingReconfirm->value)
            ->with(['product', 'quote'])
            // Oldest first: a line that has been blocking an order for two days
            // matters more than one that broke a minute ago.
            ->orderBy('updated_at')
            ->get();

        return LineItemResource::collection($lines);
    }

    public function reconfirm(ReconfirmLineItemRequest $request, LineItem $lineItem): LineItemResource
    {
        $decision = ['action' => $request->string('action')->toString()];

        if ($decision['action'] === 'amend') {
            $decision['qty'] = (int) $request->integer('qty');
            $decision['unit_price'] = (float) $request->input('unit_price');
        }

        $lineItem = $this->quotes->reconfirmLine($lineItem, $decision);

        // quote: the resource exposes quote_reference off this relation.
        return new LineItemResource($lineItem->load(['product', 'quote']));
    }
}
