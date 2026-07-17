<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PricingConfig;
use Illuminate\Http\JsonResponse;

/**
 * Public, no-account view of the single bulk-discount offer the quote engine
 * applies (PricingService::unitPriceBreakdown). The storefront needs these two
 * numbers to describe the offer honestly instead of hardcoding tiers that do
 * not exist.
 *
 * Deliberately NOT a generic pricing_configs reader: the two threshold keys are
 * already customer-facing concepts, whereas landed cost, margin and fee inputs
 * are business intel that must never reach the public storefront. The keys are
 * hardcoded so widening this endpoint takes a code change and a review.
 */
class BulkPricingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Mirrors the engine's reads (value is cast to array, so cast here).
        $bulkQty = (int) PricingConfig::value('threshold', 'bulk_qty', PHP_INT_MAX);
        $discountPct = (float) PricingConfig::value('threshold', 'bulk_discount_pct', 0);

        // An unset threshold (PHP_INT_MAX sentinel - never emit it) or a zero
        // discount both mean "no bulk offer exists". Collapse them to one shape
        // so the client has a single condition to key off.
        $hasOffer = $bulkQty > 0 && $bulkQty < PHP_INT_MAX && $discountPct > 0;

        return response()->json([
            'bulk_qty' => $hasOffer ? $bulkQty : null,
            'bulk_discount_pct' => $hasOffer ? $discountPct : 0.0,
        ]);
    }
}
