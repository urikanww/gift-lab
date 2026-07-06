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
        $specs = $request->array('line_items');

        // Batch-load all referenced products/variants up front (two queries
        // total) instead of Product::find/Variant::find per line — the old path
        // issued up to ~2 queries per line item (100-line cart → ~200 queries)
        // on this public, unauthenticated, per-cart-change endpoint.
        $productIds = array_values(array_unique(array_map(
            static fn (array $spec): int => (int) $spec['product_id'],
            $specs,
        )));

        $variantIds = array_values(array_filter(array_unique(array_map(
            static fn (array $spec): ?int => isset($spec['variant_id']) ? (int) $spec['variant_id'] : null,
            $specs,
        )), static fn (?int $id): bool => $id !== null));

        $products = $productIds === []
            ? collect()
            : Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

        $variants = $variantIds === []
            ? collect()
            : Variant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        $lines = [];

        foreach ($specs as $spec) {
            $product = $products->get((int) $spec['product_id']);

            if ($product === null || $product->publish_state !== PublishState::Published) {
                return response()->json(['message' => 'One or more products are unavailable.'], 422);
            }

            $lines[] = [
                'product' => $product,
                'variant' => isset($spec['variant_id']) ? $variants->get((int) $spec['variant_id']) : null,
                'qty' => (int) $spec['qty'],
                'has_customization' => (bool) ($spec['has_customization'] ?? false),
                'logo_size' => $spec['logo_size'] ?? null,
                'has_text' => (bool) ($spec['has_text'] ?? false),
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
