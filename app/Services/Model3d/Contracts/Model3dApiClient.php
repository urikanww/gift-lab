<?php

declare(strict_types=1);

namespace App\Services\Model3d\Contracts;

use App\Enums\Model3dSource;
use App\Services\Model3d\Model3dData;

/**
 * Pulls model metadata from a 3D source. Thingiverse (primary, free) and Cults3D
 * (secondary, commercial-use flag) live clients are provisioned when API
 * credentials/terms are cleared (spec 6.5); until then a stub serves fixtures so
 * the licence gate + catalogue flow are exercisable.
 */
interface Model3dApiClient
{
    public function fetch(Model3dSource $source, string $sourceId): ?Model3dData;
}
