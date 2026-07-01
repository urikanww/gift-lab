<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Exceptions\FeatureNotEnabledException;
use App\Models\LineItem;
use App\Services\Procurement\Contracts\ProcurementStrategy;

/**
 * SCRAPED_UV procurement (spec Phase 2). At order time the blank is bought per
 * order at marketplace retail and qty + price are re-checked — the outcome maps
 * to QTY_SHORT / PRICE_JUMPED reconfirm flows. Registered behind the interface
 * now; the marketplace re-check client is wired in Phase 2, so this guards the
 * boundary explicitly rather than silently mis-procuring a scraped line.
 */
final class ScrapedUvProcurement implements ProcurementStrategy
{
    public function procure(LineItem $lineItem): ProcurementResult
    {
        throw FeatureNotEnabledException::make('SCRAPED_UV procurement');
    }
}
