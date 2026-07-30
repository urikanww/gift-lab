<?php

declare(strict_types=1);

use App\Services\Courier\NinjaVanTrackingNumber;

/**
 * forShipment must be keyed on the shipment id (not just the quote id): a
 * multi-shipment quote books one NinjaVan order per shipment (1:1 with jobs in
 * Stage 2a, see QueueService::buildJobsForQuote), and NinjaVan rejects a second
 * order under a requested_tracking_number it has already seen - so every
 * shipment of the same quote must resolve to a distinct value.
 */
it('is distinct per shipment even for the same quote', function (): void {
    $a = NinjaVanTrackingNumber::forShipment(quoteId: 501, shipmentId: 9001);
    $b = NinjaVanTrackingNumber::forShipment(quoteId: 501, shipmentId: 9002);

    expect($a)->not->toBe($b);
});

it('is deterministic for the same quote/shipment pair (retry-safe idempotency key)', function (): void {
    $first = NinjaVanTrackingNumber::forShipment(quoteId: 501, shipmentId: 9001);
    $second = NinjaVanTrackingNumber::forShipment(quoteId: 501, shipmentId: 9001);

    expect($first)->toBe($second);
});

it('never exceeds NinjaVan\'s 9-character requested_tracking_number limit', function (): void {
    foreach ([1, 42, 9001, 123456789, 999999999999] as $shipmentId) {
        expect(strlen(NinjaVanTrackingNumber::forShipment(quoteId: 501, shipmentId: $shipmentId)))->toBeLessThanOrEqual(9);
    }
});

it('stays distinct across many shipments of the same quote, including large ids that hit the hash fallback', function (): void {
    // 36^7 (~7.8e10) is the point at which prefix + base36(shipmentId) stops
    // fitting the 9-char budget and forShipment falls back to the hashed slice -
    // pick ids comfortably past that so this actually exercises the fallback.
    $refs = collect(range(1, 50))
        ->map(fn (int $i): string => NinjaVanTrackingNumber::forShipment(quoteId: 501, shipmentId: 100_000_000_000 + $i));

    expect($refs->unique())->toHaveCount(50);
});
