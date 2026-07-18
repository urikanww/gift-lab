<?php

declare(strict_types=1);

namespace App\Services\Courier\Contracts;

use App\Services\Courier\CourierShipment;
use App\Services\Courier\CourierShipmentResult;

interface CourierClient
{
    /**
     * Create a delivery order with the carrier and return its tracking ref.
     * Throws App\Exceptions\CourierException on an unrecoverable API failure.
     */
    public function createShipment(CourierShipment $shipment): CourierShipmentResult;
}
