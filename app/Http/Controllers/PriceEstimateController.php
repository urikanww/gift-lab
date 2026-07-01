<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PublishState;
use App\Http\Requests\PriceEstimateRequest;
use App\Models\Product;
use App\Models\Variant;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;

/**
 * Public, no-account live price estimate for the designer. Event-driven (called
 * on the client when the cart changes) — never polled. Only PUBLISHED products
 * are priced; anything else is rejected so the estimate can't leak drafts.
 */
class PriceEstimateController extends Controller
{
    public function __construct(private readonly PricingService $pricing)
    {
    }

    public function __invoke(PriceEstimateRequest $request): JsonResponse
    {
        $lines = [];

        foreach ($request->array('line_items') as $spec) {
            $product = Product::find($spec['product_id']);

            if ($product === null || $product->publish_state !== PublishState::Published) {
                return response()->json(['message' => 'One or more products are unavailable.'], 422);
            }

            $lines[] = [
                'product' => $product,
                'variant' => isset($spec['variant_id']) ? Variant::find($spec['variant_id']) : null,
                'qty' => (int) $spec['qty'],
                'has_customization' => (bool) ($spec['has_customization'] ?? false),
            ];
        }

        $totals = $this->pricing->quoteTotals($lines);

        return response()->json([
            'currency' => 'SGD',
            'lines' => $totals['lines'],
            'subtotal' => $totals['subtotal'],
            'delivery' => $totals['delivery'],
            'total' => $totals['total'],
        ]);
    }
}
