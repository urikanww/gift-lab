<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReconfirmLineItemRequest;
use App\Http\Resources\LineItemResource;
use App\Models\LineItem;
use App\Services\QuoteService;

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

    public function reconfirm(ReconfirmLineItemRequest $request, LineItem $lineItem): LineItemResource
    {
        $decision = ['action' => $request->string('action')->toString()];

        if ($decision['action'] === 'amend') {
            $decision['qty'] = (int) $request->integer('qty');
            $decision['unit_price'] = (float) $request->input('unit_price');
        }

        $lineItem = $this->quotes->reconfirmLine($lineItem, $decision);

        return new LineItemResource($lineItem->load('product'));
    }
}
