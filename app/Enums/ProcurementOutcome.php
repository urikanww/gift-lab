<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcurementOutcome: string
{
    case Ok = 'OK';
    case QtyShort = 'QTY_SHORT';
    case PriceJumped = 'PRICE_JUMPED';

    public function reasonTag(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::QtyShort => 'qty_short',
            self::PriceJumped => 'price_jumped',
        };
    }
}
