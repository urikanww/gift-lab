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
        /**
         * Direct, authenticated-fetchable URL of the printable model file
         * (STL/3MF/OBJ). Null when the source exposes no file download API
         * (e.g. Cults3D) — the item then blocks on `missing_model_file`
         * until staff attach the file manually.
         */
        public ?string $downloadUrl = null,
        /**
         * Original filename of the downloadable file (source of the extension
         * when the download URL itself carries none, e.g. Thingiverse's
         * /download:{id} URLs).
         */
        public ?string $downloadFileName = null,
    ) {
    }
}
