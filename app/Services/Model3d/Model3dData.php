<?php

declare(strict_types=1);

namespace App\Services\Model3d;

use App\Enums\Model3dSource;

/**
 * Normalised 3D model metadata from a source API (Thingiverse/Cults3D) or an
 * owned/commissioned entry. `license` is the API-reported licence string; the
 * catalogue service maps it through the License enum and gates publication.
 */
final readonly class Model3dData
{
    public function __construct(
        public Model3dSource $source,
        public string $sourceId,
        public string $name,
        public string $license,
        public ?string $creatorCredit,
        public ?string $fileRef,
        public string $filamentMaterial,
        public string $filamentColor,
        public float $estGrams,
        public ?string $imageUrl = null,
        public ?string $description = null,
    ) {
    }
}
