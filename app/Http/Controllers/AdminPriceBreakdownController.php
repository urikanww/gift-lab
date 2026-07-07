<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Variant;
use App\Services\PricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-only "test a quote" breakdown for the pricing editor. Returns the full
 * itemised pricing — including internal landed cost + margin — so a superadmin
 * can see exactly what each config knob does. Staff-gated because it exposes
 * cost/margin the public price estimate hides.
 */
class AdminPriceBreakdownController extends Controller
{
    public function __construct(private readonly PricingService $pricing) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->isStaff(), 403);

        $validated = $request->validate([
            'line_items' => ['required', 'array', 'min:1'],
            'line_items.*.product_id' => ['required', 'integer'],
            'line_items.*.variant_id' => ['nullable', 'integer'],
            'line_items.*.qty' => ['required', 'integer', 'min:1'],
            'line_items.*.has_customization' => ['nullable', 'boolean'],
            'line_items.*.logo_size' => ['nullable', 'string', 'in:S,M,L'],
            'line_items.*.has_text' => ['nullable', 'boolean'],
        ]);

        $specs = $validated['line_items'];

        $productIds = array_values(array_unique(array_map(
            static fn (array $s): int => (int) $s['product_id'],
            $specs,
        )));
        $variantIds = array_values(array_filter(array_map(
            static fn (array $s): ?int => isset($s['variant_id']) ? (int) $s['variant_id'] : null,
            $specs,
        ), static fn (?int $id): bool => $id !== null));

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $variants = $variantIds === []
            ? collect()
            : Variant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        $lines = [];
        foreach ($specs as $spec) {
            $product = $products->get((int) $spec['product_id']);
            if ($product === null) {
                return response()->json(['message' => 'One or more products were not found.'], 422);
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

        return response()->json($this->pricing->quoteBreakdown($lines));
    }
}
