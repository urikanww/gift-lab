<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of truth for "which line items are waiting to be bought".
 * Shared by the buy-list read endpoint and the mark-bought actions so the list
 * a staffer sees and the set an action mutates can never drift apart.
 */
final class BuyListQuery
{
    private const OPEN_LINE_STATES = [
        LineItemState::Pending,
        LineItemState::Amended,
    ];

    private const ELIGIBLE_QUOTE_STATES = [
        QuoteState::ProofApproved,
        QuoteState::Invoiced,
        QuoteState::Confirmed,
        QuoteState::Procuring,
    ];

    /**
     * @return Builder<LineItem>
     */
    public function lines(): Builder
    {
        return LineItem::query()
            ->whereIn('line_state', array_map(fn (LineItemState $s): string => $s->value, self::OPEN_LINE_STATES))
            ->whereHas('quote', function (Builder $q): void {
                $q->whereIn('state', array_map(fn (QuoteState $s): string => $s->value, self::ELIGIBLE_QUOTE_STATES));
            })
            ->orderBy('id');
    }

    /**
     * @return Builder<LineItem>
     */
    public function linesForProduct(int $productId): Builder
    {
        return $this->lines()->where('product_id', $productId);
    }
}
