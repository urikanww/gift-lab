<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\LineItemState;
use App\Enums\ProcurementOutcome;
use App\Enums\ProductClass;
use App\Events\LineItemAwaitingReconfirm;
use App\Models\LineItem;
use App\Services\AuditLogger;
use App\Services\Procurement\Contracts\ProcurementStrategy;
use InvalidArgumentException;

/**
 * Resolves the per-class procurement strategy and drives the line-item state
 * machine from the result. This is the one gate the spec calls out: no job is
 * produced until the physical blank/filament is confirmed on the floor.
 */
final class ProcurementManager
{
    public function __construct(
        private readonly CoreProcurement $core,
        private readonly ScrapedUvProcurement $scrapedUv,
        private readonly Model3dProcurement $model3d,
        private readonly AuditLogger $audit,
    ) {
    }

    public function strategyFor(ProductClass $class): ProcurementStrategy
    {
        return match ($class) {
            ProductClass::Core => $this->core,
            ProductClass::ScrapedUv => $this->scrapedUv,
            ProductClass::Model3d => $this->model3d,
        };
    }

    /**
     * Procure a single line item, applying the resulting state transition and
     * broadcasting a reconfirm request when the re-check fails.
     */
    public function procureLine(LineItem $lineItem): ProcurementResult
    {
        if ($lineItem->line_state !== LineItemState::Pending && $lineItem->line_state !== LineItemState::Amended) {
            throw new InvalidArgumentException(
                "Line item {$lineItem->id} is not in a procurable state ({$lineItem->line_state->value})."
            );
        }

        $lineItem->transitionTo(LineItemState::Procuring);

        $product = $lineItem->product;

        if ($product === null) {
            throw new InvalidArgumentException("Line item {$lineItem->id} has no product.");
        }

        $result = $this->strategyFor($product->class)->procure($lineItem);

        $lineItem->procured_qty = $result->procuredQty;
        $lineItem->procured_price = $result->procuredPrice;

        match ($result->outcome) {
            ProcurementOutcome::Ok => $this->onProcured($lineItem),
            ProcurementOutcome::QtyShort, ProcurementOutcome::PriceJumped => $this->onReconfirm($lineItem, $result),
        };

        return $result;
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

    private function onReconfirm(LineItem $lineItem, ProcurementResult $result): void
    {
        $lineItem->save();
        $lineItem->transitionTo(LineItemState::AwaitingReconfirm);

        $this->audit->log($lineItem, 'stock.rechecked', null, [
            'outcome' => $result->outcome->value,
            'message' => $result->message,
            'procured_qty' => $result->procuredQty,
            'procured_price' => $result->procuredPrice,
        ]);

        LineItemAwaitingReconfirm::dispatch($lineItem, $result->outcome->reasonTag());
    }
}
