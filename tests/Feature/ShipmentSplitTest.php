<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Exceptions\DomainRuleException;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\Shipment;
use App\Models\ShippingAddress;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\CourierShipmentResult;
use App\Services\QueueService;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    // A UV-track (CORE) product and a 3D product, so the quote's lines fan out
    // into two production jobs (one UV job + one job for the 3D line) sharing
    // one shipment - reused from ShipmentGroupingTest.
    $this->uvProduct = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $this->model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
});

/**
 * Build the ShipmentGroupingTest fixture: one UV line + one MODEL_3D line ->
 * 2 jobs sharing one shipment. Returns [quote, jobA, jobB].
 *
 * @return array{0: Quote, 1: \App\Models\ProductionJob, 2: \App\Models\ProductionJob}
 */
function buildTwoJobOrder(object $test): array
{
    $quote = Quote::factory()->create(['company_id' => $test->company->id, 'state' => 'PROCURING']);
    $uvLine = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $test->uvProduct->id,
        'qty' => 10,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($uvLine)->approved()->create();
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $test->model3d->id,
        'qty' => 2,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal.png'],
    ]);

    $jobs = app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'));
    expect($jobs)->toHaveCount(2);

    return [$quote, $jobs[0], $jobs[1]];
}

it('moves a job into its own new shipment', function (): void {
    [$quote, $jobA, $jobB] = buildTwoJobOrder($this);
    $original = $jobA->shipment_id;
    expect($jobB->shipment_id)->toBe($original); // both start on the shared shipment

    $newShipment = app(QueueService::class)->splitJobToOwnShipment($jobA);

    expect($jobA->fresh()->shipment_id)->toBe($newShipment->id)
        ->and($jobA->fresh()->shipment_id)->not->toBe($original)
        ->and($jobB->fresh()->shipment_id)->toBe($original)
        ->and($quote->fresh()->shipments()->count())->toBe(2);
});

it('splitting then booking BOTH shipments yields 2 distinct consignment_refs', function (): void {
    // A courier spy that echoes the deterministic per-shipment tracking number,
    // so each shipment books its own distinct consignment.
    app()->bind(CourierClient::class, fn () => new class implements CourierClient
    {
        public function createShipment(CourierShipment $shipment): CourierShipmentResult
        {
            return new CourierShipmentResult($shipment->requestedTrackingNumber, 'NINJAVAN', null);
        }
    });

    [$quote, $jobA, $jobB] = buildTwoJobOrder($this);
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);

    $newShipment = app(QueueService::class)->splitJobToOwnShipment($jobA);
    $original = Shipment::findOrFail($jobB->fresh()->shipment_id);

    // Both jobs must be produced before their shipments can ship.
    app(QueueService::class)->advance($jobA->fresh(), JobState::InProduction);
    app(QueueService::class)->advance($jobB->fresh(), JobState::InProduction);

    $shipmentService = app(\App\Services\ShipmentService::class);
    $bookedNew = $shipmentService->createForShipment($newShipment->fresh());
    $bookedOriginal = $shipmentService->createForShipment($original);

    expect($bookedNew->consignment_ref)->not->toBeNull()
        ->and($bookedOriginal->consignment_ref)->not->toBeNull()
        ->and($bookedNew->consignment_ref)->not->toBe($bookedOriginal->consignment_ref);
});

it('refuses to split an already-booked shipment', function (): void {
    [$quote, $jobA, $jobB] = buildTwoJobOrder($this);

    // Book the shipment by stamping a consignment_ref directly (a booked
    // shipment can no longer be split - the parcel would be stranded).
    $shipment = Shipment::findOrFail($jobA->shipment_id);
    $shipment->update(['consignment_ref' => 'GLBOOKED']);

    expect(fn () => app(QueueService::class)->splitJobToOwnShipment($jobA->fresh()))
        ->toThrow(DomainRuleException::class);
});

it('refuses to split a lone job (already its own shipment)', function (): void {
    // A single-line quote -> one job, alone on its shipment.
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $uvLine = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->uvProduct->id,
        'qty' => 10,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($uvLine)->approved()->create();

    $jobs = app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'));
    expect($jobs)->toHaveCount(1);

    expect(fn () => app(QueueService::class)->splitJobToOwnShipment($jobs[0]))
        ->toThrow(DomainRuleException::class);
});
