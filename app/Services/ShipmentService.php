<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Exceptions\DomainRuleException;
use App\Models\ProductionJob;
use App\Models\Shipment;
use App\Models\ShippingAddress;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\NinjaVanTrackingNumber;
use Illuminate\Support\Collection;
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

    /**
     * Book the whole quote (all member jobs) onto ONE courier consignment.
     * Every job on a quote now shares a single shipment (Stage 2b), so a
     * multi-job order books exactly one NinjaVan order and advances every
     * member job to SHIPPED together - see createForShipment.
     */
    public function createForJob(ProductionJob $job): ProductionJob
    {
        // Ensure the job carries a shipment - every job built via
        // buildJobsForQuote already has a shared 1:1 shipment; this only covers
        // the legacy/factory path. createForShipment runs the guards, so the
        // idempotency/state/address checks all still fire before the billable call.
        $shipment = $job->shipment;
        if ($shipment === null) {
            $shipment = Shipment::create(['quote_id' => $job->quote_id]);
            $job->shipment()->associate($shipment)->save();
        }

        $this->createForShipment($shipment);

        return $job->fresh();
    }

    /**
     * Turn a produced shipment into ONE real carrier consignment covering every
     * member job. A multi-job order (parcel-split into several jobs sharing one
     * shipment) books a single NinjaVan order and advances all its jobs to
     * SHIPPED, so a 2-job order ships once - not once per job.
     */
    public function createForShipment(Shipment $shipment): Shipment
    {
        // Idempotency guard FIRST: the merchant-supplied tracking number is
        // deterministic per shipment, so a shipment that already carries a
        // consignment_ref has already booked a consignment - refuse to
        // double-book. TOCTOU: two truly-concurrent requests could both pass
        // this check, but the deterministic requested_tracking_number is the
        // remote-uniqueness backstop (NinjaVan rejects the duplicate booking),
        // so no second SHIPPED results.
        if ($shipment->consignment_ref !== null) {
            throw new DomainRuleException('This shipment is already booked.');
        }

        $jobs = $shipment->jobs()->with('lineItems.product')->get();
        if ($jobs->isEmpty()) {
            throw new DomainRuleException('This shipment has no items to ship.');
        }

        $quote = $shipment->quote;
        $addr = $quote?->shippingAddress;
        if ($addr === null) {
            throw new DomainRuleException('A shipping address is required before creating a shipment.');
        }

        // Validate the ship-to BEFORE the billable call: a blank required field
        // would otherwise reach NinjaVan as a 400 and surface to staff as an
        // opaque 502, instead of a specific, actionable 422.
        $this->assertShipToComplete($addr);

        // Guard BEFORE the (billable) courier call: a double-click, retry, or
        // concurrent request on a shipment whose jobs aren't all IN_PRODUCTION
        // would otherwise book a real consignment before transitionTo rejects it.
        // EVERY member job must be able to reach SHIPPED - one booking flips them
        // all, so a single not-yet-produced job blocks the whole shipment.
        if ($jobs->contains(fn ($j) => ! $j->state->canTransitionTo(JobState::Shipped))) {
            throw new DomainRuleException('Every item in this shipment must be produced before it can ship.');
        }

        // Per-SHIPMENT, not per-quote: a multi-job quote books one NinjaVan
        // order per shipment, so a number derived from the quote id alone would
        // collide across a quote's own shipments (NinjaVan rejects the second as
        // a duplicate). See NinjaVanTrackingNumber::forShipment.
        $trackingNumber = NinjaVanTrackingNumber::forShipment((int) $quote->id, (int) $shipment->id);
        $deliveryStartDate = $quote->needed_by?->toDateString()
            ?? now()->addDays((int) config('services.ninjavan.lead_days', 2))->toDateString();

        // Clamp to today: a quote's needed_by can be in the past by the time a
        // shipment actually ships (e.g. an overdue order), and NinjaVan rejects a
        // delivery_start_date before today.
        $today = now()->startOfDay()->toDateString();
        if ($deliveryStartDate < $today) {
            $deliveryStartDate = $today;
        }

        $courierShipment = new CourierShipment(
            reference: (string) ($quote->tracking_code ?? $quote->id),
            recipientName: $addr->recipient_name, phone: $addr->phone, email: $addr->email,
            line1: $addr->line1, line2: $addr->line2, city: $addr->city, state: $addr->state,
            postalCode: $addr->postal_code, country: $addr->country, notes: $addr->notes,
            parcelCount: 1,
            requestedTrackingNumber: $trackingNumber, deliveryStartDate: $deliveryStartDate,
            weightKg: $this->weightKgForShipment($jobs),
        );

        $result = $this->courier->createShipment($courierShipment); // throws CourierException on failure

        return DB::transaction(function () use ($shipment, $jobs, $result): Shipment {
            // Write the consignment onto the shared shipment FIRST, so every
            // member job's SHIPPED advance below sees the tracking ref already in
            // place (the M19 milestone reads consignment_ref/carrier off it).
            $shipment->consignment_ref = $result->trackingRef;
            $shipment->carrier = Carrier::tryFrom($result->carrier) ?? Carrier::Other;
            $shipment->label_url = $result->labelUrl;
            $shipment->save();

            // Advance EVERY member job to SHIPPED with no consignment args - the
            // shipment already carries them (set above). The M19 dedup fires ONE
            // "on its way" email for the whole order (the first job notifies; the
            // rest see a sibling already shipped and skip). Set the relation so
            // advance() reads the just-updated shipment in memory.
            foreach ($jobs as $j) {
                $j->setRelation('shipment', $shipment);
                $this->queue->advance($j, JobState::Shipped);
            }

            return $shipment->fresh();
        });
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
     * Real parcel weight in kg for a whole shipment: the grams of every member
     * job summed together. Falls back to config default_weight_kg when ANY
     * member job's weight is unknown (no line items, or a line whose product has
     * no weight recorded) - a partial sum would understate the real weight, so
     * "unknown" degrades the whole shipment to the configured default rather
     * than a misleadingly precise partial figure.
     *
     * @param  Collection<int, ProductionJob>  $jobs
     */
    private function weightKgForShipment(Collection $jobs): float
    {
        $default = (float) config('services.ninjavan.default_weight_kg', 1);

        $totalGrams = 0.0;
        foreach ($jobs as $job) {
            $grams = $this->gramsForJob($job);
            if ($grams === null) {
                return $default; // unknown weight anywhere -> whole shipment degrades
            }

            $totalGrams += $grams;
        }

        return $totalGrams > 0 ? round($totalGrams / 1000, 3) : $default;
    }

    /**
     * The job's real weight in grams (product weight x qty, summed over its line
     * items), or null when it can't be known precisely: the job has no line
     * items, or any line's product has no recorded weight. Callers treat null as
     * "unknown" and degrade to the configured default rather than a partial sum.
     */
    private function gramsForJob(ProductionJob $job): ?float
    {
        $lineItems = $job->lineItems()->with('product')->get();
        if ($lineItems->isEmpty()) {
            return null;
        }

        $totalGrams = 0.0;
        foreach ($lineItems as $line) {
            $weightGrams = $line->product?->weight;
            if ($weightGrams === null) {
                return null;
            }

            $totalGrams += (float) $weightGrams * (int) $line->qty;
        }

        return $totalGrams;
    }
}
