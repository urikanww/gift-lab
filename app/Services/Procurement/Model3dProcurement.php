<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Exceptions\FeatureNotEnabledException;
use App\Models\LineItem;
use App\Services\Procurement\Contracts\ProcurementStrategy;

/**
 * MODEL_3D procurement (spec Phase 2). Prints in-house against filament stock;
 * checks filament availability and drafts a reorder if low. Registered behind
 * the interface now; the filament-check logic is wired in Phase 2, so this
 * guards the boundary explicitly rather than silently mis-procuring a 3D line.
 */
final class Model3dProcurement implements ProcurementStrategy
{
    public function procure(LineItem $lineItem): ProcurementResult
    {
        throw FeatureNotEnabledException::make('MODEL_3D procurement');
    }
}
