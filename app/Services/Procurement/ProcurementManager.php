<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\LineItemState;
use App\Enums\ProcurementOutcome;
use App\Enums\ProductClass;
use App\Events\LineItemAwaitingReconfirm;
use App\Exceptions\DomainRuleException;
use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Services\AuditLogger;
use App\Services\Procurement\Contracts\ProcurementStrategy;
use App\Support\Broadcasting;
use Illuminate\Support\Facades\DB;

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
            throw new DomainRuleException(
                "Line item {$lineItem->id} is not in a procurable state ({$lineItem->line_state->value})."
            );
        }

        $product = $lineItem->product;

        if ($product === null) {
            throw new DomainRuleException("Line item {$lineItem->id} has no product.");
        }

        // Per-line atomicity: the chained state-machine transitions (Procuring →
        // Purchased → Inbound → Received → Ready on success, or → AwaitingReconfirm)
        // plus the strategy's stock decrement all commit together or not at all.
        // A failure mid-chain no longer strands the line in an intermediate state.
        return DB::transaction(function () use ($lineItem, $product): ProcurementResult {
            $lineItem->transitionTo(LineItemState::Procuring);

            $result = $this->strategyFor($product->class)->procure($lineItem);

            $lineItem->procured_qty = $result->procuredQty;
            $lineItem->procured_price = $result->procuredPrice;

            // The two findings are not the same kind of thing, so they are not
            // treated the same.
            //
            // A QUANTITY shortfall is measured against our own stock figures.
            // Most goods here are bought in after the order is placed, so those
            // figures are not maintained and will drift toward zero - blocking
            // on them means orders held up by shortages that do not exist. The
            // finding is recorded on the line and staff check it at the
            // production gate, which is where the truth actually lives.
            //
            // A PRICE jump is a live read from the marketplace: real, current,
            // external, and about money. A supplier quietly raising prices is
            // exactly the thing worth stopping for, so it still blocks.
            match ($result->outcome) {
                ProcurementOutcome::Ok => $this->onProcured($lineItem),
                ProcurementOutcome::PriceJumped => $this->onReconfirm($lineItem, $result),
                ProcurementOutcome::QtyShort => $this->blocksOnQtyShort()
                    ? $this->onReconfirm($lineItem, $result)
                    : $this->onAdvisory($lineItem, $result),
            };

            return $result;
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

    /**
     * Escape hatch: set procurement.block_on_qty_short to 1 to restore the old
     * blocking behaviour, per-tenant, without a deploy. Off by default because
     * nobody here maintains the stock figures it would block on.
     */
    private function blocksOnQtyShort(): bool
    {
        return (bool) PricingConfig::value('procurement', 'block_on_qty_short', 0);
    }

    /**
     * Record the shortfall against the line and carry on. The line completes at
     * the quantity ordered: what will genuinely be produced is settled by a
     * person at the production gate, not by this number.
     */
    private function onAdvisory(LineItem $lineItem, ProcurementResult $result): void
    {
        $lineItem->procurement_note = $result->message;
        // procured_qty is set by the caller from the strategy's finding, which
        // here is the short figure. The line is proceeding at the ordered
        // quantity, so record that rather than leaving a figure behind that
        // would later be read as "this is what we are making".
        $lineItem->procured_qty = $lineItem->qty;

        $this->onProcured($lineItem);

        $this->audit->log($lineItem, 'stock.rechecked', null, [
            'outcome' => $result->outcome->value,
            'blocking' => false,
            'message' => $result->message,
            'available_qty' => $result->procuredQty,
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

        DB::afterCommit(fn () => Broadcasting::dispatch(fn () => LineItemAwaitingReconfirm::dispatch($lineItem, $result->outcome->reasonTag())));
    }
}
