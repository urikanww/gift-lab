<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\LineItemState;
use App\Http\Requests\ReconfirmLineItemRequest;
use App\Http\Resources\LineItemResource;
use App\Models\LineItem;
use App\Models\Quote;
use App\Services\QuoteService;
use Illuminate\Http\Request;
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
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manageProduction', Quote::class);

        $lines = LineItem::query()
            ->where('line_state', LineItemState::AwaitingReconfirm->value)
            ->with(['product', 'quote'])
            // Generic staff-list filters — all optional, all narrowing. The
            // AWAITING_RECONFIRM constraint above still bounds everything.
            ->when($request->filled('q'), function ($query) use ($request): void {
                // ?q[]=abc arrives as an array; casting that to string is a
                // TypeError (a 500 on the search box), so ignore non-strings.
                $raw = $request->input('q');
                if (! is_string($raw)) {
                    return;
                }

                $term = trim($raw);
                if ($term === '') {
                    return;
                }

                // Wildcards escaped so a stray % or _ narrows literally, matching
                // the other search endpoints. Nested where so neither orWhereHas
                // escapes the AWAITING_RECONFIRM / eager-load scope above.
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(function ($w) use ($like): void {
                    $w->whereHas('quote', fn ($q) => $q->where('reference', 'like', $like))
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $like));
                });
            })
            ->when($request->filled('updated_from'), fn ($query) => $query->whereDate('updated_at', '>=', (string) $request->query('updated_from')))
            ->when($request->filled('updated_to'), fn ($query) => $query->whereDate('updated_at', '<=', (string) $request->query('updated_to')));

        // Sort — a small allowlist. Default is oldest-first: a line that has been
        // blocking an order for two days matters more than one that broke a
        // minute ago. `newest` flips it for staff who want the latest breaks.
        $lines = (match ((string) $request->query('sort', 'oldest')) {
            'newest' => $lines->orderByDesc('updated_at'),
            default => $lines->orderBy('updated_at'),
        })->get();

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
