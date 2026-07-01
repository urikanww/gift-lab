<?php

declare(strict_types=1);

namespace App\Enums;

enum ProductClass: string
{
    case Core = 'CORE';
    case ScrapedUv = 'SCRAPED_UV';
    case Model3d = 'MODEL_3D';

    /**
     * The production track this class feeds in the shared queue.
     */
    public function track(): JobTrack
    {
        return $this === self::Model3d ? JobTrack::ThreeD : JobTrack::Uv;
    }
}
