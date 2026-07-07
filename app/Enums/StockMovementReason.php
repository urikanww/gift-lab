<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a stock movement happened. The ledger is append-only, so the reason is
 * the audit story for every change to a variant's on-hand count.
 */
enum StockMovementReason: string
{
    case Init = 'INIT';        // opening balance / backfill of an existing count
    case Restock = 'RESTOCK';  // stock received (purchase, procurement arrival)
    case Sale = 'SALE';        // consumed by a committed order
    case Return = 'RETURN';    // order cancelled/refunded, stock returned
    case Adjust = 'ADJUST';    // manual staff correction
    case Scrap = 'SCRAP';      // damaged / lost / failed print
}
