<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\ReorderState;
use App\Enums\StockMovementReason;
use App\Models\LineItem;
use App\Models\SupplierReorder;
use App\Services\Procurement\Contracts\ProcurementStrategy;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;

/**
 * CORE blank procurement: decrement on-hand variant stock. Price is the known
 * internal cost, so a CORE line never PRICE_JUMPS - it only OK's or QTY_SHORTs.
 * Drafts a bulk supplier reorder when a variant drops to/below its threshold.
 */
final class CoreProcurement implements ProcurementStrategy
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function procure(LineItem $lineItem): ProcurementResult
    {
        $variant = $lineItem->variant;
        $unitPrice = (float) $lineItem->unit_price;

        if ($variant === null) {
            return ProcurementResult::qtyShort(
                0,
                $unitPrice,
                'CORE line has no variant assigned; cannot source blank.',
            );
        }

        return DB::transaction(function () use ($lineItem, $variant, $unitPrice): ProcurementResult {
            // Lock the variant row to prevent oversell under concurrent procurement.
            $variant = $variant->newQuery()->lockForUpdate()->find($variant->getKey());

            // Shortfall handling forks on the product's on-demand policy:
            //  - allow_backorder OFF: keep today's behaviour - short-ship and send
            //    the line to reconfirm (customer/staff decide on reduced qty).
            //  - allow_backorder ON: fulfil the full qty and let on-hand go
            //    negative. The negative balance is the procurement worklist (a
            //    supplier reorder is drafted below), so the order is never blocked.
            if ($variant->stock_on_hand < $lineItem->qty
                && ! (bool) ($lineItem->product?->allow_backorder ?? false)) {
                return ProcurementResult::qtyShort(
                    $variant->stock_on_hand,
                    $unitPrice,
                    "Only {$variant->stock_on_hand} of {$lineItem->qty} on hand.",
                );
            }

            // Consume stock through the ledger (append-only movement + cached
            // on-hand update), never a direct column write. Backorder drives the
            // balance negative here.
            $this->ledger->record($variant, -$lineItem->qty, StockMovementReason::Sale, $lineItem);

            if ($variant->isBelowThreshold()) {
                // A restock buffer (2× threshold) plus whatever a backorder drove
                // negative - clamped to at least 1 so a zero-threshold variant
                // never drafts a useless 0-qty reorder onto the buy-list.
                $deficit = $variant->stock_on_hand < 0 ? -$variant->stock_on_hand : 0;
                $reorderQty = max($variant->reorder_threshold * 2, 1) + $deficit;
                $this->draftReorder($variant->id, $reorderQty);
            }

            return ProcurementResult::ok($lineItem->qty, $unitPrice);
        });
    }

    private function draftReorder(int $variantId, int $qty): void
    {
        // Avoid duplicate open drafts for the same variant.
        $exists = SupplierReorder::query()
            ->where('variant_id', $variantId)
            ->whereIn('state', [ReorderState::Draft->value, ReorderState::Approved->value, ReorderState::Ordered->value])
            ->exists();

        if ($exists) {
            return;
        }

        SupplierReorder::create([
            'variant_id' => $variantId,
            'filament_id' => null,
            'sku' => null,
            'qty' => $qty,
            'state' => ReorderState::Draft->value,
            'approved_by' => null,
        ]);
    }
}
