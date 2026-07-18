<?php

declare(strict_types=1);

namespace App\Services\Courier;

final readonly class CourierShipmentResult
{
    public function __construct(
        public string $trackingRef,   // AWB / tracking number from the carrier
        public string $carrier,       // matches App\Enums\Carrier value, e.g. 'NINJAVAN'
        public ?string $labelUrl,     // printable waybill, if the carrier returns one
    ) {}
}
