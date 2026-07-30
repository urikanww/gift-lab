<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\StockMode;
use App\Models\Product;

/**
 * Scraped-UV completeness gate (spec 6.4). Returns the reason tags blocking
 * publication; an empty array means the product is complete and publishable.
 * Reason tags: missing_price, missing_dimensions, not_printable,
 * stock_unreadable, source_dead.
 */
final class CompletenessGate
{
    /**
     * @return array<int, string>
     */
    public function reasons(Product $product): array
    {
        $reasons = [];

        if ($product->base_cost === null || (float) $product->base_cost <= 0) {
            $reasons[] = 'missing_price';
        }

        $dims = $product->dimensions;
        $hasDims = is_array($dims)
            && ! empty($dims['l']) && ! empty($dims['w']) && ! empty($dims['h']);
        if (! $hasDims || $product->weight === null) {
            $reasons[] = 'missing_dimensions';
        }

        if (! $product->is_printable || $product->print_method === null) {
            $reasons[] = 'not_printable';
        }

        // Only STOCKED blanks need a readable stock estimate. A MAKE_TO_ORDER
        // (buy-per-order) blank has no seller quantity to read - a null estimate
        // is expected there, checked by a human at procurement - so it must not
        // block publish; STOCKED items still gate on it.
        if ($product->stock_mode === StockMode::Stocked && $product->stock_estimate === null) {
            $reasons[] = 'stock_unreadable';
        }

        return $reasons;
    }

    public function isComplete(Product $product): bool
    {
        return $this->reasons($product) === [];
    }
}
