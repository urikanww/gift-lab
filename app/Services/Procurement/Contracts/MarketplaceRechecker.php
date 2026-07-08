<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Models\Product;

/**
 * Procurement-time re-check of a scraped-UV blank: the authoritative read of
 * live qty + unit price at the marketplace (spec principle 3). Distinct from the
 * daily catalogue re-sync - this fires when a line is actually being procured.
 */
interface MarketplaceRechecker
{
    /**
     * @return array{available_qty: int, unit_price: float}
     */
    public function recheck(Product $product): array;
}
