<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\OrderMilestone;
use App\Mail\OrderMilestoneMail;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\ProductionJob;
use App\Models\Quote;
use App\Models\Shipment;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Courier\Contracts\CourierClient;
use App\Services\Courier\CourierShipment;
use App\Services\Courier\CourierShipmentResult;
use App\Services\QueueService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\Mail;

/**
 * Build a 2-job order sharing ONE shipment (ShipmentGroupingTest fixture): one
 * UV line + one MODEL_3D line fan out into 2 jobs on a single shipment, plus a
 * complete ship-to so the shipment can be booked. Returns [quote, jobA, jobB].
 *
 * @return array{0: Quote, 1: ProductionJob, 2: ProductionJob}
 */
function buildBookableTwoJobOrder(): array
{
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $uvProduct = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);

    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING', 'created_by' => $buyer->id]);
    $uvLine = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $uvProduct->id,
        'qty' => 10,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($uvLine)->approved()->create();
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $model3d->id,
        'qty' => 2,
        'customization' => ['mode' => 'designer', 'print_file_ref' => 'artwork/decal.png'],
    ]);

    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);

    $jobs = app(QueueService::class)->buildJobsForQuote($quote->load('lineItems.product'));
    expect($jobs)->toHaveCount(2);

    return [$quote, $jobs[0], $jobs[1]];
}

/**
 * A courier spy that echoes the deterministic per-shipment tracking number, so
 * each shipment books its own distinct consignment (reused from ShipmentSplitTest).
 */
function bindEchoingCourier(): void
{
    app()->bind(CourierClient::class, fn () => new class implements CourierClient
    {
        public function createShipment(CourierShipment $shipment): CourierShipmentResult
        {
            return new CourierShipmentResult($shipment->requestedTrackingNumber, 'NINJAVAN', null);
        }
    });
}

function shippedMailCount(): int
{
    return Mail::queued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $m): bool => $m->milestone === OrderMilestone::Shipped,
    )->count();
}

// M19: an unsplit order's jobs all share ONE shipment (one parcel). The buyer
// should get one "on its way" email for that parcel, not one per job. Both jobs
// are placed on the SAME shipment here, then shipped through the manual advance
// path - the first notifies, the second sees a shipment-mate already shipped
// and skips.
it('sends the shipped email once per shipment, not per job', function (): void {
    Mail::fake();
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'READY', 'created_by' => $buyer->id]);
    $shipment = Shipment::create(['quote_id' => $quote->id]);
    $jobA = ProductionJob::factory()->create(['quote_id' => $quote->id, 'shipment_id' => $shipment->id, 'state' => 'IN_PRODUCTION']);
    $jobB = ProductionJob::factory()->create(['quote_id' => $quote->id, 'shipment_id' => $shipment->id, 'state' => 'IN_PRODUCTION']);

    $queue = app(QueueService::class);
    $queue->advance($jobA->fresh(), JobState::Shipped, 'SP-SHARED', Carrier::from('SINGPOST'));
    $queue->advance($jobB->fresh(), JobState::Shipped);

    $shipped = Mail::queued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $m): bool => $m->milestone === OrderMilestone::Shipped,
    );
    expect($shipped)->toHaveCount(1);
});

// M19 (unsplit): an order that stays as ONE shipment (2 jobs sharing it) books
// once via createForShipment and must send exactly ONE "on its way" email - the
// first job notifies, its shipment-mate sees a sibling shipped and skips.
it('sends exactly one shipped email for an unsplit order (one shipment, two jobs)', function (): void {
    Mail::fake();
    bindEchoingCourier();
    [$quote, $jobA, $jobB] = buildBookableTwoJobOrder();

    // Both jobs must be produced before their shared shipment can ship.
    app(QueueService::class)->advance($jobA->fresh(), JobState::InProduction);
    app(QueueService::class)->advance($jobB->fresh(), JobState::InProduction);

    $shipment = Shipment::findOrFail($jobA->fresh()->shipment_id);
    app(ShipmentService::class)->createForShipment($shipment);

    expect(shippedMailCount())->toBe(1);
});

// M19 (split): once a job is split into its OWN shipment, the order has two
// parcels booked separately - each parcel must notify once, so the buyer gets
// TWO "on its way" emails (each with its own tracking). Before the per-shipment
// dedup fix, parcel B was suppressed because parcel A had already shipped.
it('sends one shipped email per parcel for a split order (two shipments)', function (): void {
    Mail::fake();
    bindEchoingCourier();
    [$quote, $jobA, $jobB] = buildBookableTwoJobOrder();

    // Split jobA onto its own shipment -> two shipments, one job each.
    $newShipment = app(QueueService::class)->splitJobToOwnShipment($jobA);
    $originalShipment = Shipment::findOrFail($jobB->fresh()->shipment_id);

    // Produce both jobs, then book each shipment separately (at different times).
    app(QueueService::class)->advance($jobA->fresh(), JobState::InProduction);
    app(QueueService::class)->advance($jobB->fresh(), JobState::InProduction);

    app(ShipmentService::class)->createForShipment($newShipment->fresh());
    expect(shippedMailCount())->toBe(1); // parcel A notified

    app(ShipmentService::class)->createForShipment($originalShipment);
    expect(shippedMailCount())->toBe(2); // parcel B notified too - one per parcel
});
