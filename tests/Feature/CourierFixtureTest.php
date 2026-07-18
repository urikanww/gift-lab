<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;

it('fixture returns a deterministic tracking ref', function (): void {
    $client = app(CourierClient::class); // fixture in the testing env
    $shipment = new CourierShipment(
        reference: 'GL-2041',
        recipientName: 'Rachel Tan', phone: '+6591234567', email: null,
        line1: '1 Marina Blvd', line2: null, city: 'Singapore', state: null,
        postalCode: '018989', country: 'SG', notes: null,
        parcelCount: 1,
        requestedTrackingNumber: 'GL1AB', deliveryStartDate: '2026-07-20',
    );

    $result = $client->createShipment($shipment);
    $again = $client->createShipment($shipment);

    expect($result->trackingRef)->toBe('GL1AB')
        ->and($result->trackingRef)->toBe($again->trackingRef)
        ->and($result->carrier)->toBe(Carrier::NinjaVan->value);
});
