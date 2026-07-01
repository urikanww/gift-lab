<?php

declare(strict_types=1);

namespace App\Enums;

enum PrintMethod: string
{
    case Uv = 'UV';
    case Fdm = 'FDM';
    case Resin = 'RESIN';
}
