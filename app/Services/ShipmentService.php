<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Exceptions\DomainRuleException;
use App\Models\ProductionJob;
use App\Models\ShippingAddress;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\NinjaVanTrackingNumber;
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

        // Validate the ship-to BEFORE the billable call: a blank required field
        // would otherwise reach NinjaVan as a 400 and surface to staff as an
        // opaque 502, instead of a specific, actionable 422.
        $this->assertShipToComplete($addr);

        // Per-JOB, not per-quote: a multi-job quote (one UV job + one per 3D
        // line) books one NinjaVan order per job, so a number derived from the
        // quote id alone would collide across a quote's own jobs (NinjaVan
        // rejects the second as a duplicate, and only one job's webhook would
        // ever find a match). See NinjaVanTrackingNumber::forJob.
        $trackingNumber = NinjaVanTrackingNumber::forJob((int) $quote->id, (int) $job->id);
        $deliveryStartDate = $quote->needed_by?->toDateString()
            ?? now()->addDays((int) config('services.ninjavan.lead_days', 2))->toDateString();

        // Clamp to today: a quote's needed_by can be in the past by the time a
        // job actually ships (e.g. an overdue order), and NinjaVan rejects a
        // delivery_start_date before today.
        $today = now()->startOfDay()->toDateString();
        if ($deliveryStartDate < $today) {
            $deliveryStartDate = $today;
        }

        $shipment = new CourierShipment(
            reference: (string) $quote->reference,
            recipientName: $addr->recipient_name, phone: $addr->phone, email: $addr->email,
            line1: $addr->line1, line2: $addr->line2, city: $addr->city, state: $addr->state,
            postalCode: $addr->postal_code, country: $addr->country, notes: $addr->notes,
            parcelCount: 1,
            requestedTrackingNumber: $trackingNumber, deliveryStartDate: $deliveryStartDate,
            weightKg: $this->weightKgForJob($job),
        );

        $result = $this->courier->createShipment($shipment); // throws CourierException on failure

        return DB::transaction(fn () => $this->queue->advance(
            $job,
            JobState::Shipped,
            consignmentRef: $result->trackingRef,
            carrier: Carrier::tryFrom($result->carrier) ?? Carrier::Other,
            labelUrl: $result->labelUrl,
        ));
    }

    /**
     * Required ship-to fields for a NinjaVan order. A blank one must fail fast
     * with a specific, staff-actionable 422 rather than reaching the courier and
     * bouncing back as an opaque 502 on NinjaVan's own 400.
     */
    private function assertShipToComplete(ShippingAddress $addr): void
    {
        $required = [
            'recipient name' => $addr->recipient_name,
            'phone' => $addr->phone,
            'postal code' => $addr->postal_code,
            'country' => $addr->country,
            'address line 1' => $addr->line1,
        ];

        $missing = [];
        foreach ($required as $label => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new DomainRuleException(
                'The shipping address is missing required field(s): '.implode(', ', $missing).'.'
            );
        }
    }

    /**
     * Real parcel weight in kg, summed from the job's line items (product
     * weight in grams x qty). Falls back to config default_weight_kg when the
     * job has no line items or any line's product has no weight recorded -
     * a partial sum would understate the real weight, so "unknown" degrades to
     * the configured default rather than a misleadingly precise partial figure.
     */
    private function weightKgForJob(ProductionJob $job): float
    {
        $default = (float) config('services.ninjavan.default_weight_kg', 1);

        $lineItems = $job->lineItems()->with('product')->get();
        if ($lineItems->isEmpty()) {
            return $default;
        }

        $totalGrams = 0.0;
        foreach ($lineItems as $line) {
            $weightGrams = $line->product?->weight;
            if ($weightGrams === null) {
                return $default;
            }

            $totalGrams += (float) $weightGrams * (int) $line->qty;
        }

        return $totalGrams > 0 ? round($totalGrams / 1000, 3) : $default;
    }
}
