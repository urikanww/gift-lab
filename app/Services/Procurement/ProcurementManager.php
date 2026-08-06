<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\LineItemState;
use App\Exceptions\DomainRuleException;
use App\Models\LineItem;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Advances a bought line through the line-item state machine to READY. The
 * automatic per-class sourcing engine was removed once the manual buy list
 * became the only buying path; what remains is the "staff bought it" advance.
 */
final class ProcurementManager
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Staff have physically bought (or printed) this line. Advance it straight
     * to READY through the line-item state chain, WITHOUT any sourcing: no stock
     * decrement, no marketplace re-check, no filament draw. The bill and
     * job-build are driven by QuoteService.
     */
    public function markBought(LineItem $lineItem): void
    {
        if ($lineItem->line_state !== LineItemState::Pending && $lineItem->line_state !== LineItemState::Amended) {
            throw new DomainRuleException(
                "Line item {$lineItem->id} is not in a buyable state ({$lineItem->line_state->value})."
            );
        }

        DB::transaction(function () use ($lineItem): void {
            $lineItem->transitionTo(LineItemState::Procuring);
            $lineItem->procured_qty = $lineItem->qty;
            $lineItem->procured_price = $lineItem->unit_price;
            $this->onProcured($lineItem);

            $this->audit->log($lineItem, 'line_item.bought', null, [
                'procured_qty' => $lineItem->procured_qty,
                'procured_price' => $lineItem->procured_price,
            ]);
        });
    }

    private function onProcured(LineItem $lineItem): void
    {
        $lineItem->save();
        $lineItem->transitionTo(LineItemState::Purchased);
        $lineItem->transitionTo(LineItemState::Inbound);
        $lineItem->transitionTo(LineItemState::Received);
        $lineItem->transitionTo(LineItemState::Ready);

        $this->audit->log($lineItem, 'line_item.procured', null, [
            'procured_qty' => $lineItem->procured_qty,
            'procured_price' => $lineItem->procured_price,
        ]);
    }
}
