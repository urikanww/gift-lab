<?php

declare(strict_types=1);

namespace App\Enums;

enum Model3dSource: string
{
    case Thingiverse = 'THINGIVERSE';
    case Cults3d = 'CULTS3D';
    case Makerworld = 'MAKERWORLD';
    case Owned = 'OWNED';
}
