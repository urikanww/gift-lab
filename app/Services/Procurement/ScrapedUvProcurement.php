<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Services\Procurement\Contracts\MarketplaceRechecker;
use App\Services\Procurement\Contracts\ProcurementStrategy;

/**
 * SCRAPED_UV procurement (spec 4 / 5.2): the blank is bought per order at
 * marketplace retail, so qty + price are re-checked at procurement time against
 * the authoritative live read. A shortfall → QTY_SHORT; a price above the quoted
 * unit by more than the configured tolerance → PRICE_JUMPED; otherwise OK.
 */
final class ScrapedUvProcurement implements ProcurementStrategy
{
    public function __construct(private readonly MarketplaceRechecker $rechecker)
    {
    }

    public function procure(LineItem $lineItem): ProcurementResult
    {
        $product = $lineItem->product;
        $quotedUnit = (float) $lineItem->unit_price;

        if ($product === null) {
            return ProcurementResult::qtyShort(0, $quotedUnit, 'Scraped line has no product.');
        }

        $live = $this->rechecker->recheck($product);
        $available = $live['available_qty'];
        $livePrice = $live['unit_price'];

        if ($available < $lineItem->qty) {
            return ProcurementResult::qtyShort(
                $available,
                $livePrice,
                "Marketplace has {$available} of {$lineItem->qty} at re-check.",
            );
        }

        $tolerance = (float) PricingConfig::value('catalogue', 'price_jump_pct', 10);
        if ($quotedUnit > 0 && $livePrice > $quotedUnit * (1 + $tolerance / 100)) {
            return ProcurementResult::priceJumped(
                $lineItem->qty,
                $livePrice,
                "Re-check price {$livePrice} exceeds quoted {$quotedUnit} beyond {$tolerance}%.",
            );
        }

        return ProcurementResult::ok($lineItem->qty, $livePrice);
    }
}
