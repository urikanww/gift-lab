<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\ProcurementOutcome;

/**
 * Immutable outcome of a procurement attempt for one line item.
 */
final readonly class ProcurementResult
{
    public function __construct(
        public ProcurementOutcome $outcome,
        public int $procuredQty,
        public float $procuredPrice,
        public string $message = '',
    ) {
    }

    public static function ok(int $qty, float $price): self
    {
        return new self(ProcurementOutcome::Ok, $qty, $price);
    }

    public static function qtyShort(int $available, float $price, string $message): self
    {
        return new self(ProcurementOutcome::QtyShort, $available, $price, $message);
    }

    public static function priceJumped(int $qty, float $newPrice, string $message): self
    {
        return new self(ProcurementOutcome::PriceJumped, $qty, $newPrice, $message);
    }
}
