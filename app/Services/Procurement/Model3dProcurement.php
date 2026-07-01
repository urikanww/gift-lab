<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\ReorderState;
use App\Models\Filament;
use App\Models\LineItem;
use App\Models\SupplierReorder;
use App\Services\Procurement\Contracts\ProcurementStrategy;
use Illuminate\Support\Facades\DB;

/**
 * MODEL_3D procurement (spec 6.5): printed in-house, so it consumes filament
 * rather than a sourced blank. Decrements the matching Filament row by
 * est_grams x qty; a shortfall → QTY_SHORT (reported in whole printable units);
 * drafts a bulk reorder when the spool drops to/below threshold. Price is the
 * in-house unit price, so a 3D line never PRICE_JUMPS.
 */
final class Model3dProcurement implements ProcurementStrategy
{
    public function procure(LineItem $lineItem): ProcurementResult
    {
        $product = $lineItem->product;
        $unitPrice = (float) $lineItem->unit_price;

        if ($product === null) {
            return ProcurementResult::qtyShort(0, $unitPrice, 'MODEL_3D line has no product.');
        }

        $perUnitGrams = (float) ($product->est_grams ?? $product->weight ?? 0);
        if ($perUnitGrams <= 0) {
            return ProcurementResult::qtyShort(0, $unitPrice, 'Product has no filament weight estimate.');
        }

        $neededGrams = $perUnitGrams * $lineItem->qty;

        return DB::transaction(function () use ($product, $lineItem, $unitPrice, $perUnitGrams, $neededGrams): ProcurementResult {
            $filament = Filament::query()
                ->where('material', $product->filament_material)
                ->where('color', $product->filament_color)
                ->lockForUpdate()
                ->first();

            if ($filament === null) {
                return ProcurementResult::qtyShort(
                    0,
                    $unitPrice,
                    "No filament stock for {$product->filament_material}/{$product->filament_color}.",
                );
            }

            if ((float) $filament->qty_on_hand < $neededGrams) {
                $printable = (int) floor((float) $filament->qty_on_hand / $perUnitGrams);

                return ProcurementResult::qtyShort(
                    $printable,
                    $unitPrice,
                    "Filament covers {$printable} of {$lineItem->qty} units.",
                );
            }

            $filament->qty_on_hand = (float) $filament->qty_on_hand - $neededGrams;
            $filament->save();

            if ($filament->isBelowThreshold()) {
                $this->draftReorder($filament->id, (float) $filament->reorder_threshold * 2);
            }

            return ProcurementResult::ok($lineItem->qty, $unitPrice);
        });
    }

    private function draftReorder(int $filamentId, float $grams): void
    {
        $exists = SupplierReorder::query()
            ->where('filament_id', $filamentId)
            ->whereIn('state', [ReorderState::Draft->value, ReorderState::Approved->value, ReorderState::Ordered->value])
            ->exists();

        if ($exists) {
            return;
        }

        SupplierReorder::create([
            'variant_id' => null,
            'filament_id' => $filamentId,
            'sku' => null,
            'qty' => $grams,
            'state' => ReorderState::Draft->value,
            'approved_by' => null,
        ]);
    }
}
