<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Courier\NinjaVanStatusMapper;
use App\Services\QueueService;
use App\Services\ShipmentService;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

/**
 * The Needs-attention surface: returned/failed parcels split off the in-transit
 * board onto their own list (they can't be "marked delivered"). Mirrors
 * ReturnResolutionTest's SHIPPED-job setup + staff authorization.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create([
        'company_id' => $this->company->id,
        'role' => 'buyer',
    ]);
});

/**
 * A job carried to SHIPPED and then stamped with a given courier status label -
 * the same construction ReturnResolutionTest's returnedShippedJob() uses, with
 * the last_courier_status label as a parameter.
 */
function shippedJobWithStatus(string $label, string $consignmentRef): ProductionJob
{
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'PROCURING',
        'created_by' => test()->buyer->id,
    ]);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id, 'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567', 'line1' => '1 Marina Blvd',
        'postal_code' => '018989', 'country' => 'SG',
    ]);

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $queue->advance($job, JobState::InProduction);
    $job = $queue->advance($job, JobState::Shipped, $consignmentRef, Carrier::NinjaVan);

    // Courier status lives on the shipment now (Stage 2a).
    $shipment = $job->shipment;
    $shipment->last_courier_status = $label;
    $shipment->last_courier_status_at = now();
    $shipment->save();

    return $job->fresh();
}

/**
 * A UV line + a 3D line collapse into two production jobs sharing ONE shipment,
 * booked to SHIPPED together via createForShipment - the 2-job-one-shipment
 * shape the surfaces must dedupe to a single row. Returns the shared shipment.
 */
function twoJobShipment(): \App\Models\Shipment
{
    $uvProduct = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $model3d = Product::factory()->create(['class' => 'MODEL_3D', 'print_method' => 'FDM']);
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'PROCURING',
        'created_by' => test()->buyer->id,
    ]);
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

    $queue = app(QueueService::class);
    $jobs = $queue->buildJobsForQuote($quote->load('lineItems.product'));
    foreach ($jobs as $job) {
        $queue->advance($job->fresh(), JobState::InProduction);
    }

    $shipment = $jobs->first()->shipment;
    app(ShipmentService::class)->createForShipment($shipment->fresh());

    return $shipment->fresh();
}

it('dedupes a 2-job returned shipment to ONE needs-attention row', function (): void {
    $shipment = twoJobShipment();
    expect($shipment->jobs()->count())->toBe(2);
    $shipment->last_courier_status = NinjaVanStatusMapper::LABEL_RETURNED;
    $shipment->last_courier_status_at = now();
    $shipment->save();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $rows = collect(getJson('/api/production-jobs/needs-attention')->assertOk()->json('data'))
        ->where('shipment_id', $shipment->id);

    expect($rows)->toHaveCount(1);
});

it('dedupes a 2-job in-transit shipment to ONE in-transit row', function (): void {
    $shipment = twoJobShipment(); // normal SHIPPED, no needs-attention status
    expect($shipment->jobs()->count())->toBe(2);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $rows = collect(getJson('/api/production-jobs/in-transit')->assertOk()->json('data'))
        ->where('shipment_id', $shipment->id);

    expect($rows)->toHaveCount(1);
});

it('lists a returned/failed parcel in needs-attention and not in in-transit', function (): void {
    $returned = shippedJobWithStatus(NinjaVanStatusMapper::LABEL_RETURNED, 'NVSGNA0001');
    $plain = shippedJobWithStatus('In transit', 'NVSGNA0002');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $needsAttentionIds = collect(getJson('/api/production-jobs/needs-attention')
        ->assertOk()->json('data'))->pluck('id');
    $inTransitIds = collect(getJson('/api/production-jobs/in-transit')
        ->assertOk()->json('data'))->pluck('id');

    expect($needsAttentionIds)->toContain($returned->id)
        ->and($needsAttentionIds)->not->toContain($plain->id)
        ->and($inTransitIds)->toContain($plain->id)
        ->and($inTransitIds)->not->toContain($returned->id);
});

it('exposes a needs_attention flag on the resource', function (): void {
    $failed = shippedJobWithStatus(NinjaVanStatusMapper::LABEL_ATTEMPT_FAILED, 'NVSGNA0003');

    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $row = collect(getJson('/api/production-jobs/needs-attention')
        ->assertOk()->json('data'))->firstWhere('id', $failed->id);

    expect($row)->not->toBeNull()
        ->and($row['needs_attention'])->toBeTrue();
});
