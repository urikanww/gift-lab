<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts;

use App\Models\LineItem;
use App\Services\Procurement\ProcurementResult;

/**
 * One procurement strategy per product class (spec 4). The order/quote/proof/
 * queue spine is shared; only procurement differs, so it lives behind this
 * single interface: procure(lineItem) -> ProcurementResult.
 */
interface ProcurementStrategy
{
    public function procure(LineItem $lineItem): ProcurementResult;
}
