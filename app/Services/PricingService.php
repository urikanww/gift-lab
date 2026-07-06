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
     * @param  array<int, array{product: Product, variant: ?Variant, qty: int, has_customization: bool, logo_size?: ?string, has_text?: bool}>  $lines
     * @return array{lines: array<int, array{unit_price: float, line_total: float}>, subtotal: float, delivery: float, total: float}
     */
    public function quoteTotals(array $lines): array
    {
        $customizationFee = (float) PricingConfig::value('fee', 'customization_flat', 0);
        // Per-unit component for work repeated on every piece — name/text
        // personalisation (spec 6.1: combinable with logo, priced additively;
        // audit D9/D10). Charged only on lines that carry text.
        $customizationPerUnit = (float) PricingConfig::value('fee', 'customization_per_unit', 0);
        // Per-unit surcharge by logo footprint band (S/M/L). A bigger logo
        // covers more decoration area (more ink / longer pass), so it costs
        // more per piece. Absent size → no surcharge (blank/legacy lines).
        $bySize = (array) PricingConfig::value('fee', 'customization_by_size', []);
        $setupFee = (float) PricingConfig::value('fee', 'setup_fee', 0);
        // UV decoration pass on a MODEL_3D part (audit G7): unitPrice skips
        // the per-unit print fee for MODEL_3D (machine time is in landed
        // cost), but a customized 3D item still gets a UV pass — recover it.
        $printPerUnit = (array) PricingConfig::value('print_cost', 'per_unit', []);
        $uvDecorPerUnit = (float) ($printPerUnit['UV'] ?? 0);

        $priced = [];
        $subtotal = 0.0;
        $totalWeightG = 0.0;

        foreach ($lines as $line) {
            $unit = $this->unitPrice($line['product'], $line['variant'], $line['qty']);
            $lineTotal = $unit * $line['qty'];

            if ($line['has_customization']) {
                $size = $line['logo_size'] ?? null;
                $sizeSurcharge = $size !== null ? (float) ($bySize[$size] ?? 0) : 0.0;
                // Additive fee structure (audit D10): one flat fee per
                // customized line + per-unit logo-size surcharge + per-unit
                // text fee when a name/text layer is present.
                $textPerUnit = ($line['has_text'] ?? false) ? $customizationPerUnit : 0.0;
                $decorPerUnit = $line['product']->class === ProductClass::Model3d ? $uvDecorPerUnit : 0.0;
                $lineTotal += $customizationFee + ($textPerUnit + $sizeSurcharge + $decorPerUnit) * $line['qty'];
            }

            $lineTotal = round($lineTotal, 2);
            $subtotal += $lineTotal;
            // Shipment weight: MODEL_3D items with no catalogued weight fall
            // back to their filament estimate (audit G8) so delivery pricing
            // never treats a printed part as weightless.
            $lineWeightG = (float) ($line['product']->weight ?? 0);
            if ($lineWeightG <= 0 && $line['product']->class === ProductClass::Model3d) {
                $lineWeightG = (float) ($line['product']->est_grams ?? 0);
            }
            $totalWeightG += $lineWeightG * $line['qty'];

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
