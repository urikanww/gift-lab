<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductClass;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Variant;

/**
 * Quote engine pricing. Every number is read from pricing_configs at quote time
 * (spec principle 5) — nothing here is hardcoded. All money is SGD, rounded to
 * 2 dp at the boundary.
 */
final class PricingService
{
    /**
     * Per-unit landed (production) cost by product class. MODEL_3D has no
     * sourced blank — its landed cost is filament consumed plus machine time,
     * both from config (minutes-per-gram is the proxy until a slicer
     * integration supplies measured print times). Everything else is the
     * blank cost plus the variant delta.
     */
    public function landedCost(Product $product, ?Variant $variant): float
    {
        if ($product->class === ProductClass::Model3d) {
            $grams = (float) ($product->est_grams ?? 0);
            $filamentPerGram = (float) PricingConfig::value('print_cost', 'filament_per_gram', 0);
            $machineRate = (float) PricingConfig::value('print_cost', 'machine_rate_per_min', 0);

            // Slicer-measured print time when available; grams-based proxy
            // otherwise.
            $minutes = $product->est_print_minutes !== null
                ? (float) $product->est_print_minutes
                : $grams * (float) PricingConfig::value('print_cost', 'minutes_per_gram', 0);

            return $grams * $filamentPerGram + $minutes * $machineRate;
        }

        return (float) $product->base_cost + (float) ($variant?->price_delta ?? 0);
    }

    /**
     * Price a single line's per-unit price (excludes flat per-line fees).
     */
    public function unitPrice(Product $product, ?Variant $variant, int $qty): float
    {
        $landed = $this->landedCost($product, $variant);

        $marginPct = (float) PricingConfig::value('margin', 'default_pct', 0);
        $marged = $landed * (1 + $marginPct / 100);

        // MODEL_3D machine time is already inside landed cost — the flat
        // per-unit print fee applies only to decorate-a-blank methods.
        $printPerUnit = 0.0;
        if ($product->class !== ProductClass::Model3d) {
            $printCosts = (array) PricingConfig::value('print_cost', 'per_unit', []);
            $method = $product->print_method?->value;
            $printPerUnit = (float) ($printCosts[$method] ?? 0);
        }

        $unit = $marged + $printPerUnit;

        $bulkQty = (int) PricingConfig::value('threshold', 'bulk_qty', PHP_INT_MAX);
        if ($qty >= $bulkQty) {
            $discountPct = (float) PricingConfig::value('threshold', 'bulk_discount_pct', 0);
            $unit *= (1 - $discountPct / 100);
        }

        return round($unit, 2);
    }

    /**
     * Compute a full quote total from resolved line specs.
     *
     * @param  array<int, array{product: Product, variant: ?Variant, qty: int, has_customization: bool}>  $lines
     * @return array{lines: array<int, array{unit_price: float, line_total: float}>, subtotal: float, delivery: float, total: float}
     */
    public function quoteTotals(array $lines): array
    {
        $customizationFee = (float) PricingConfig::value('fee', 'customization_flat', 0);
        // Per-unit component for work repeated on every piece (e.g. embossed
        // personalisation adds print time per unit, unlike a one-off UV setup).
        $customizationPerUnit = (float) PricingConfig::value('fee', 'customization_per_unit', 0);
        $setupFee = (float) PricingConfig::value('fee', 'setup_fee', 0);

        $priced = [];
        $subtotal = 0.0;
        $totalWeightG = 0.0;

        foreach ($lines as $line) {
            $unit = $this->unitPrice($line['product'], $line['variant'], $line['qty']);
            $lineTotal = $unit * $line['qty'];

            if ($line['has_customization']) {
                $lineTotal += $customizationFee + $customizationPerUnit * $line['qty'];
            }

            $lineTotal = round($lineTotal, 2);
            $subtotal += $lineTotal;
            $totalWeightG += (float) ($line['product']->weight ?? 0) * $line['qty'];

            $priced[] = ['unit_price' => $unit, 'line_total' => $lineTotal];
        }

        $subtotal = round($subtotal + $setupFee, 2);
        $delivery = $this->deliveryFor($totalWeightG);
        $total = round($subtotal + $delivery, 2);

        return [
            'lines' => $priced,
            'subtotal' => $subtotal,
            'delivery' => $delivery,
            'total' => $total,
        ];
    }

    /**
     * Delivery price by total shipment weight (grams), from the config table.
     */
    public function deliveryFor(float $totalWeightG): float
    {
        $table = (array) PricingConfig::value('delivery', 'table', []);

        $last = 0.0;

        foreach ($table as $tier) {
            $last = round((float) $tier['price'], 2);
            $max = $tier['max_weight_g'] ?? null;
            if ($max === null || $totalWeightG <= (float) $max) {
                return $last;
            }
        }

        // Heavier than every configured tier: charge the heaviest tier rather
        // than falling through to free shipping on a misconfigured table.
        return $last;
    }

    /**
     * Margin-floor guard (spec 6.2): an admin amendment must not price a unit
     * below landed cost plus the configured floor margin.
     */
    public function isAboveMarginFloor(float $proposedUnit, float $landedCost): bool
    {
        $floorPct = (float) PricingConfig::value('margin', 'floor_pct', 0);

        return $proposedUnit >= round($landedCost * (1 + $floorPct / 100), 2);
    }
}
