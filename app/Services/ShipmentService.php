<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Exceptions\DomainRuleException;
use App\Models\ProductionJob;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use Illuminate\Support\Facades\DB;

/**
 * Turns a produced order into a real carrier shipment: build the shipment from
 * the quote's ship-to, call the courier, and write the returned tracking ref +
 * carrier onto the job as it transitions to SHIPPED (reusing QueueService's
 * SHIPPED path so broadcasts/audit stay consistent).
 */
final class ShipmentService
{
    public function __construct(
        private readonly CourierClient $courier,
        private readonly QueueService $queue,
    ) {}

    public function createForJob(ProductionJob $job): ProductionJob
    {
        $quote = $job->quote;

        // Idempotency guard FIRST: the merchant-supplied tracking number is
        // deterministic per quote, so a job that already carries a consignment_ref
        // has already booked a consignment - refuse to double-book.
        // TOCTOU: two truly-concurrent requests could both pass this check, but the
        // deterministic requested_tracking_number is the remote-uniqueness backstop
        // (NinjaVan rejects the duplicate booking), so no second SHIPPED results.
        if ($job->consignment_ref !== null) {
            throw new DomainRuleException('This job already has a shipment.');
        }

        $addr = $quote->shippingAddress;
        if ($addr === null) {
            throw new DomainRuleException('A shipping address is required before creating a shipment.');
        }

        // Guard BEFORE the (billable) courier call: a double-click, retry, or
        // concurrent request on an already-SHIPPED / non-IN_PRODUCTION job would
        // otherwise book a second real consignment before transitionTo rejects it.
        if (! $job->state->canTransitionTo(JobState::Shipped)) {
            throw new DomainRuleException('This job cannot be shipped from its current state.');
        }

        $trackingNumber = \App\Services\Courier\NinjaVanTrackingNumber::forQuote((int) $quote->id);
        $deliveryStartDate = $quote->needed_by?->toDateString()
            ?? now()->addDays((int) config('services.ninjavan.lead_days', 2))->toDateString();

        $shipment = new CourierShipment(
            reference: (string) ($quote->tracking_code ?? $quote->id),
            recipientName: $addr->recipient_name, phone: $addr->phone, email: $addr->email,
            line1: $addr->line1, line2: $addr->line2, city: $addr->city, state: $addr->state,
            postalCode: $addr->postal_code, country: $addr->country, notes: $addr->notes,
            parcelCount: 1,
            requestedTrackingNumber: $trackingNumber, deliveryStartDate: $deliveryStartDate,
        );

        $result = $this->courier->createShipment($shipment); // throws CourierException on failure

        return DB::transaction(fn () => $this->queue->advance(
            $job,
            JobState::Shipped,
            consignmentRef: $result->trackingRef,
            carrier: Carrier::tryFrom($result->carrier) ?? Carrier::Other,
        ));
    }
}
