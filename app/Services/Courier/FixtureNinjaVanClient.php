<?php

declare(strict_types=1);

namespace App\Services\Courier;

use App\Enums\Carrier;
use App\Services\Courier\Contracts\CourierClient;

/**
 * Deterministic fake for local/testing: no network, echoes the merchant-supplied
 * tracking number back as the tracking ref (mirroring real NinjaVan behavior
 * where the merchant number IS the tracking number) so tests can assert on it.
 */
final class FixtureNinjaVanClient implements CourierClient
{
    public function createShipment(CourierShipment $shipment): CourierShipmentResult
    {
        return new CourierShipmentResult(
            trackingRef: $shipment->requestedTrackingNumber,
            carrier: Carrier::NinjaVan->value,
            labelUrl: null,
        );
    }
}
