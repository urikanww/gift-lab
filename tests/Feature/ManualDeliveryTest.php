<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\OrderMilestone;
use App\Enums\QuoteState;
use App\Mail\OrderMilestoneMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\ProductionJob;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\Shipment;
use App\Models\ShippingAddress;
use App\Models\User;
use App\Services\Courier\NinjaVanStatusMapper;
use App\Services\QueueService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/**
 * Staff manual "mark delivered" fallback for when NinjaVan's delivery webhook
 * never arrives. Distinct from the returned-parcel resolution: this is the
 * happy path (parcel really was delivered) that the webhook normally drives.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

/** A job carried to SHIPPED with no delivery webhook yet (last_courier_status null). */
function shippedJobAwaitingDelivery(string $consignmentRef = 'NVSGDEL0001'): ProductionJob
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

    return $job->fresh();
}

/**
 * A UV line + a 3D line collapse into two production jobs that share ONE
 * shipment (Stage 2b grouping), booked to SHIPPED together via
 * createForShipment - the exact 2-job-one-shipment shape the cascade must
 * cover. Returns the shared shipment.
 */
function twoJobShipmentDelivery(): Shipment
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

it('cascades a manual delivery across the whole shipment: both member jobs + quote close, one delivered email', function (): void {
    $shipment = twoJobShipmentDelivery();
    $jobs = $shipment->jobs()->get();
    expect($jobs)->toHaveCount(2);

    // Fake AFTER the fixture ships, so only the delivery action's mail counts.
    Mail::fake();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$jobs->first()->id}/mark-delivered", [
        'note' => 'Buyer confirmed receipt of the whole order by phone.',
    ])->assertOk()->assertJsonPath('data.state', 'CLOSED');

    foreach ($jobs as $job) {
        expect($job->fresh()->state)->toBe(JobState::Closed);
    }

    expect($shipment->quote()->first()->state)->toBe(QuoteState::Closed);

    // Exactly ONE "delivered" milestone for the whole order (fired off the
    // quote's single READY->CLOSED transition when the last member closes).
    $delivered = Mail::queued(
        OrderMilestoneMail::class,
        fn (OrderMilestoneMail $m): bool => $m->milestone === OrderMilestone::Delivered,
    );
    expect($delivered)->toHaveCount(1);
});

it('lets staff mark a shipped job delivered: job + quote close, with a manual audit', function (): void {
    $job = shippedJobAwaitingDelivery();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/mark-delivered", [
        'note' => 'Buyer confirmed receipt by phone; webhook never arrived.',
    ])->assertOk()->assertJsonPath('data.state', 'CLOSED');

    $job->refresh();
    expect($job->state)->toBe(JobState::Closed)
        ->and($job->shipment->last_courier_status)->toContain('confirmed by staff');

    expect($job->quote()->first()->state)->toBe(QuoteState::Closed);

    // LT15: closing the order advances its READY lines to DELIVERED, so a
    // completed order no longer shows a lingering "Ready" line.
    $states = LineItem::where('quote_id', $job->quote_id)->get()
        ->pluck('line_state')->map->value->all();
    expect($states)->toContain('DELIVERED')->not->toContain('READY');

    expect(AuditLog::where('auditable_type', ProductionJob::class)
        ->where('auditable_id', $job->id)
        ->where('event', 'production_job.manually_delivered')
        ->exists())->toBeTrue();
});

it('rejects marking a job delivered when it is not shipped', function (): void {
    $job = shippedJobAwaitingDelivery();
    app(QueueService::class)->advance($job, JobState::Closed); // already delivered
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/mark-delivered")
        ->assertStatus(422);
});

it('directs a returned/failed parcel to the returned-parcel resolution instead', function (): void {
    $job = shippedJobAwaitingDelivery();
    $job->shipment->last_courier_status = NinjaVanStatusMapper::map('Returned to Sender')->label;
    $job->shipment->last_courier_status_at = now();
    $job->shipment->save();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$job->id}/mark-delivered")
        ->assertStatus(422);

    expect($job->fresh()->state)->toBe(JobState::Shipped);
});

it('blocks a non-staff buyer from marking delivered', function (): void {
    $job = shippedJobAwaitingDelivery();
    Sanctum::actingAs($this->buyer);

    $this->postJson("/api/production-jobs/{$job->id}/mark-delivered")->assertForbidden();
    expect($job->fresh()->state)->toBe(JobState::Shipped);
});
