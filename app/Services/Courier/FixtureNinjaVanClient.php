<?php

declare(strict_types=1);

namespace App\Services\Courier;

use App\Enums\Carrier;
use App\Services\Courier\Contracts\CourierClient;

/**
 * Deterministic fake for local/testing: no network, a stable tracking ref
 * derived from the order reference so tests can assert on it.
 */
final class FixtureNinjaVanClient implements CourierClient
{
    public function createShipment(CourierShipment $shipment): CourierShipmentResult
    {
        return new CourierShipmentResult(
            trackingRef: 'NVSGTEST'.substr(md5($shipment->reference), 0, 10),
            carrier: Carrier::NinjaVan->value,
            labelUrl: null,
        );
    }
}
