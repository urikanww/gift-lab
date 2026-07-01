<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\ReorderState;
use App\Models\LineItem;
use App\Models\SupplierReorder;
use App\Services\Procurement\Contracts\ProcurementStrategy;
use Illuminate\Support\Facades\DB;

/**
 * CORE blank procurement: decrement on-hand variant stock. Price is the known
 * internal cost, so a CORE line never PRICE_JUMPS — it only OK's or QTY_SHORTs.
 * Drafts a bulk supplier reorder when a variant drops to/below its threshold.
 */
final class CoreProcurement implements ProcurementStrategy
{
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

            if ($variant->stock_on_hand < $lineItem->qty) {
                return ProcurementResult::qtyShort(
                    $variant->stock_on_hand,
                    $unitPrice,
                    "Only {$variant->stock_on_hand} of {$lineItem->qty} on hand.",
                );
            }

            $variant->stock_on_hand -= $lineItem->qty;
            $variant->save();

            if ($variant->isBelowThreshold()) {
                $this->draftReorder($variant->id, $variant->reorder_threshold * 2);
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
