<?php

declare(strict_types=1);

namespace App\Enums;

enum StockMode: string
{
    case Stocked = 'STOCKED';
    case MakeToOrder = 'MAKE_TO_ORDER';
}
