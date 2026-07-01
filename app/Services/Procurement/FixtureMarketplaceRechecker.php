<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Models\Product;
use App\Services\Procurement\Contracts\MarketplaceRechecker;

/**
 * Default MarketplaceRechecker binding. The live re-check is a human/admin or
 * contracted-supplier confirmation (never a bot checkout, spec 7); until that is
 * wired, this returns the product's indicative estimate + price so the
 * procurement state machine is fully exercisable. Tests override per product.
 */
final class FixtureMarketplaceRechecker implements MarketplaceRechecker
{
    /** @var array<int, array{available_qty: int, unit_price: float}> */
    private array $overrides = [];

    public function for(int $productId, int $availableQty, float $unitPrice): self
    {
        $this->overrides[$productId] = ['available_qty' => $availableQty, 'unit_price' => $unitPrice];

        return $this;
    }

    /**
     * @return array{available_qty: int, unit_price: float}
     */
    public function recheck(Product $product): array
    {
        return $this->overrides[$product->id] ?? [
            'available_qty' => $product->stock_estimate ?? 0,
            'unit_price' => (float) $product->base_cost,
        ];
    }
}
