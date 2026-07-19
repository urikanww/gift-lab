<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductClass;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Variant;

/**
 * Quote engine pricing. Every number is read from pricing_configs at quote time
 * (spec principle 5) - nothing here is hardcoded. All money is SGD, rounded to
 * 2 dp at the boundary.
 */
final class PricingService
{
    /**
     * Per-unit landed (production) cost by product class. MODEL_3D has no
     * sourced blank - its landed cost is filament consumed plus machine time,
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
        return $this->unitPriceBreakdown($product, $variant, $qty)['unit_price'];
    }

    /**
     * Per-unit price with its cost components exposed (landed cost, margin,
     * print, bulk discount). This is the single source of the unit maths;
     * unitPrice() returns only the final figure. INTERNAL - landed cost and
     * margin must never reach the public storefront (business-intel leak).
     *
     * @return array{landed_cost: float, margin: float, print_per_unit: float, bulk_discount: float, unit_price: float, overridden: bool, price_override: ?float}
     */
    public function unitPriceBreakdown(Product $product, ?Variant $variant, int $qty): array
    {
        $landed = $this->landedCost($product, $variant);

        // Superadmin price override (spec 2026-07-07): a fixed per-unit price that
        // replaces the whole dynamic build-up. The variant delta still adds on top;
        // the bulk discount is skipped; the override may sit below landed cost. The
        // derived components are zeroed but landed cost stays for reference.
        if ($product->price_override !== null) {
            $override = (float) $product->price_override;
            $delta = (float) ($variant?->price_delta ?? 0);

            return [
                'landed_cost' => round($landed, 2),
                'margin' => 0.0,
                'print_per_unit' => 0.0,
                'bulk_discount' => 0.0,
                'unit_price' => round($override + $delta, 2),
                'overridden' => true,
                'price_override' => round($override, 2),
            ];
        }

        $marginPct = (float) PricingConfig::value('margin', 'default_pct', 0);
        $marginAmount = $landed * $marginPct / 100;
        $marged = $landed + $marginAmount;

        // MODEL_3D machine time is already inside landed cost - the flat
        // per-unit print fee applies only to decorate-a-blank methods.
        $printPerUnit = 0.0;
        if ($product->class !== ProductClass::Model3d) {
            $printCosts = (array) PricingConfig::value('print_cost', 'per_unit', []);
            $method = $product->print_method?->value;
            $printPerUnit = (float) ($printCosts[$method] ?? 0);
        }

        $beforeBulk = $marged + $printPerUnit;

        $bulkDiscount = 0.0;
        $bulkQty = (int) PricingConfig::value('threshold', 'bulk_qty', PHP_INT_MAX);
        if ($qty >= $bulkQty) {
            $discountPct = (float) PricingConfig::value('threshold', 'bulk_discount_pct', 0);
            $bulkDiscount = $beforeBulk * $discountPct / 100;
        }

        return [
            'landed_cost' => round($landed, 2),
            'margin' => round($marginAmount, 2),
            'print_per_unit' => round($printPerUnit, 2),
            'bulk_discount' => round($bulkDiscount, 2),
            'unit_price' => round($beforeBulk - $bulkDiscount, 2),
            'overridden' => false,
            'price_override' => null,
        ];
    }

    /**
     * Compute a full quote total from resolved line specs.
     *
     * @param  array<int, array{product: Product, variant: ?Variant, qty: int, has_customization: bool, logo_size?: ?string, has_text?: bool}>  $lines
     * @return array{lines: array<int, array{unit_price: float, line_total: float}>, subtotal: float, delivery: float, total: float, delivery_reliable: bool}
     */
    public function quoteTotals(array $lines): array
    {
        $customizationFee = (float) PricingConfig::value('fee', 'customization_flat', 0);
        // Per-unit component for work repeated on every piece - name/text
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
        // cost), but a customized 3D item still gets a UV pass - recover it.
        $printPerUnit = (array) PricingConfig::value('print_cost', 'per_unit', []);
        $uvDecorPerUnit = (float) ($printPerUnit['UV'] ?? 0);

        $priced = [];
        $subtotal = 0.0;
        $totalWeightG = 0.0;
        // The scraped catalogue seeds placeholder weight/dimensions on some
        // products (e.g. 0.5 g / a 1 cm cube), which collapse the chargeable
        // weight to ~0 and park every order in the cheapest delivery tier -
        // a misleadingly low fee. Flag the estimate as unreliable when any
        // line's per-unit chargeable weight sits below a trust floor so the
        // storefront can hide the number and defer to the staff-confirmed quote.
        $deliveryReliable = true;

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
            // Shipment weight is the chargeable (max of actual + volumetric)
            // weight so a light-but-bulky item ships at its volume; MODEL_3D with
            // neither falls back to filament grams (audit G8).
            $unitWeightG = $this->chargeableWeightG($line['product']);
            $totalWeightG += $unitWeightG * $line['qty'];
            if (! $this->weightIsTrustworthy($unitWeightG)) {
                $deliveryReliable = false;
            }

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
            'delivery_reliable' => $deliveryReliable,
        ];
    }

    /**
     * Whether a per-unit chargeable weight (grams) is high enough to trust for a
     * delivery quote. Anything under the configured floor almost certainly means
     * the product is missing real weight AND dimensions (placeholder scraped
     * data), so the derived delivery tier would understate the true cost.
     */
    private function weightIsTrustworthy(float $chargeableWeightG): bool
    {
        $floor = (float) PricingConfig::value('delivery', 'min_trustworthy_g', 20);

        return $chargeableWeightG >= $floor;
    }

    /**
     * Full, itemised breakdown of a quote for the staff pricing tester: every
     * per-unit cost component and per-line fee, plus quote-level setup, delivery
     * and total. INTERNAL/staff-only - it exposes landed cost + margin, which the
     * public price estimate deliberately hides. Mirrors quoteTotals' maths.
     *
     * @param  array<int, array{product: Product, variant: ?Variant, qty: int, has_customization: bool, logo_size?: ?string, has_text?: bool}>  $lines
     * @return array<string, mixed>
     */
    public function quoteBreakdown(array $lines): array
    {
        $customizationFee = (float) PricingConfig::value('fee', 'customization_flat', 0);
        $customizationPerUnit = (float) PricingConfig::value('fee', 'customization_per_unit', 0);
        $bySize = (array) PricingConfig::value('fee', 'customization_by_size', []);
        $setupFee = (float) PricingConfig::value('fee', 'setup_fee', 0);
        $printPerUnit = (array) PricingConfig::value('print_cost', 'per_unit', []);
        $uvDecorPerUnit = (float) ($printPerUnit['UV'] ?? 0);

        $priced = [];
        $subtotal = 0.0;
        $totalWeightG = 0.0;

        foreach ($lines as $line) {
            $product = $line['product'];
            $qty = $line['qty'];
            $bd = $this->unitPriceBreakdown($product, $line['variant'], $qty);
            $unitsTotal = round($bd['unit_price'] * $qty, 2);

            $flat = 0.0;
            $sizeTotal = 0.0;
            $textTotal = 0.0;
            $uvTotal = 0.0;
            if ($line['has_customization']) {
                $flat = $customizationFee;
                $size = $line['logo_size'] ?? null;
                $sizeTotal = ($size !== null ? (float) ($bySize[$size] ?? 0) : 0.0) * $qty;
                $textTotal = (($line['has_text'] ?? false) ? $customizationPerUnit : 0.0) * $qty;
                $uvTotal = ($product->class === ProductClass::Model3d ? $uvDecorPerUnit : 0.0) * $qty;
            }

            $lineTotal = round($unitsTotal + $flat + $sizeTotal + $textTotal + $uvTotal, 2);
            $subtotal += $lineTotal;

            $totalWeightG += $this->chargeableWeightG($product) * $qty;

            $priced[] = [
                'name' => $product->name,
                'qty' => $qty,
                'landed_cost' => $bd['landed_cost'],
                'margin' => $bd['margin'],
                'print_per_unit' => $bd['print_per_unit'],
                'bulk_discount' => $bd['bulk_discount'],
                'unit_price' => $bd['unit_price'],
                'units_total' => $unitsTotal,
                'customization_flat' => round($flat, 2),
                'size_surcharge_total' => round($sizeTotal, 2),
                'text_fee_total' => round($textTotal, 2),
                'uv_decor_total' => round($uvTotal, 2),
                'line_total' => $lineTotal,
            ];
        }

        $subtotal = round($subtotal + $setupFee, 2);
        $delivery = $this->deliveryFor($totalWeightG);

        return [
            'currency' => 'SGD',
            'lines' => $priced,
            'setup_fee' => round($setupFee, 2),
            'subtotal' => $subtotal,
            'delivery_weight_g' => round($totalWeightG, 1),
            'delivery' => $delivery,
            'total' => round($subtotal + $delivery, 2),
        ];
    }

    /**
     * Chargeable shipping weight (grams) for one unit: the greater of the
     * actual weight and the volumetric/dimensional weight - the standard courier
     * rule where a light-but-bulky item ships at its volume. Volumetric grams =
     * (L × W × H in mm) / 5000 (equivalent to the cm³/5000 kg convention).
     * If neither is available, a MODEL_3D part falls back to its filament
     * estimate so a print is never treated as weightless.
     */
    private function chargeableWeightG(Product $product): float
    {
        $actual = (float) ($product->weight ?? 0);

        $dims = $product->dimensions ?? [];
        $l = (float) ($dims['l'] ?? 0);
        $w = (float) ($dims['w'] ?? 0);
        $h = (float) ($dims['h'] ?? 0);
        $volumetric = ($l > 0 && $w > 0 && $h > 0) ? ($l * $w * $h) / 5000 : 0.0;

        $chargeable = max($actual, $volumetric);

        if ($chargeable <= 0 && $product->class === ProductClass::Model3d) {
            $chargeable = (float) ($product->est_grams ?? 0);
        }

        return $chargeable;
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
