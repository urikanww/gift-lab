<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\QuoteState;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Invoice;
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

/**
 * Staff resolution flow for a returned/failed NinjaVan parcel. Prereq: the
 * webhook (NinjaVanWebhookController) already flags a returned/failed parcel
 * by writing NinjaVanStatusMapper's needsAttention label onto
 * production_jobs.last_courier_status while the job stays SHIPPED - this
 * suite covers what staff can then DO about it.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create([
        'company_id' => $this->company->id,
        'role' => 'buyer',
    ]);
});

/**
 * A job carried to SHIPPED and then flagged by the courier as returned - the
 * exact state a job is left in by NinjaVanWebhookController after a
 * "Returned to Sender" event.
 */
function returnedShippedJob(string $consignmentRef = 'NVSGRET0001'): ProductionJob
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

    // Mirrors what NinjaVanWebhookController writes for a "Returned to
    // Sender" event, without needing to sign+post a webhook payload here. The
    // courier footprint lives on the shipment now (Stage 2a).
    $shipment = $job->shipment;
    $shipment->last_courier_status = NinjaVanStatusMapper::map('Returned to Sender')->label;
    $shipment->last_courier_status_at = now();
    $shipment->save();

    return $job->fresh();
}

/**
 * A UV line + a 3D line collapse into two production jobs sharing ONE shipment,
 * booked to SHIPPED together via createForShipment, then flagged returned - the
 * 2-job-one-shipment shape the return cascade must cover. Returns the shipment.
 */
function twoJobReturnedShipment(): \App\Models\Shipment
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

    // Flag the whole shipment returned (as the webhook would).
    $shipment = $shipment->fresh();
    $shipment->last_courier_status = NinjaVanStatusMapper::map('Returned to Sender')->label;
    $shipment->last_courier_status_at = now();
    $shipment->save();

    return $shipment->fresh();
}

it('cascades reship across the whole shipment: both member jobs to IN_PRODUCTION, footprint cleared once, re-bookable', function (): void {
    $shipment = twoJobReturnedShipment();
    $jobs = $shipment->jobs()->get();
    expect($jobs)->toHaveCount(2);
    expect($shipment->consignment_ref)->not->toBeNull();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$jobs->first()->id}/resolve-return", [
        'disposition' => 'reship',
    ])->assertOk()->assertJsonPath('data.state', 'IN_PRODUCTION');

    foreach ($jobs as $job) {
        expect($job->fresh()->state)->toBe(JobState::InProduction);
    }

    $shipment = $shipment->fresh();
    expect($shipment->consignment_ref)->toBeNull()
        ->and($shipment->carrier)->toBeNull()
        ->and($shipment->last_courier_status)->toBeNull();

    // No SHIPPED sibling left to block the all-shippable guard: the whole
    // shipment re-books in one call (the footprint being cleared once is what
    // frees the unique consignment_ref index for a fresh booking) and every
    // member job ships again together.
    $rebooked = app(ShipmentService::class)->createForShipment($shipment->fresh());
    expect($rebooked->consignment_ref)->not->toBeNull();
    foreach ($shipment->jobs()->get() as $job) {
        expect($job->fresh()->state)->toBe(JobState::Shipped);
    }
});

it('cascades close across the whole shipment: both member jobs CLOSED', function (): void {
    $shipment = twoJobReturnedShipment();
    $jobs = $shipment->jobs()->get();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/production-jobs/{$jobs->first()->id}/resolve-return", [
        'disposition' => 'close',
    ])->assertOk()->assertJsonPath('data.state', 'CLOSED');

    foreach ($jobs as $job) {
        expect($job->fresh()->state)->toBe(JobState::Closed);
    }

    expect($shipment->quote()->first()->state)->toBe(QuoteState::Closed);
});

it('resolves close: advances the job and closes the quote', function (): void {
    $job = returnedShippedJob();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/resolve-return", [
        'disposition' => 'close',
        'note' => 'Parcel lost in transit; writing off.',
    ])->assertOk()->assertJsonPath('data.state', 'CLOSED');

    $job->refresh();
    expect($job->state)->toBe(JobState::Closed);

    $quote = $job->quote()->first();
    expect($quote->state)->toBe(QuoteState::Closed);

    expect(AuditLog::where('auditable_type', ProductionJob::class)
        ->where('auditable_id', $job->id)
        ->where('event', 'production_job.return_resolved')
        ->exists())->toBeTrue();
});

it('resolves cancel_credit: cancels the quote, voids the invoice, and mints one credit note', function (): void {
    $job = returnedShippedJob();
    $quote = $job->quote()->first();

    Invoice::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-'.$quote->id,
        'invoice_ref' => null,
        'terms' => null,
        'payment_state' => 'PAID',
        'amount' => 199.90,
        'gst_amount' => 16.51,
        'gst_rate' => 9,
        'currency' => 'SGD',
        'issued_by' => null,
        'issued_at' => now(),
    ]);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/resolve-return", [
        'disposition' => 'cancel_credit',
        'note' => 'Buyer declined a reship; refund via credit note.',
    ])->assertOk();

    $quote->refresh();
    expect($quote->state)->toBe(QuoteState::Cancelled);

    $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();
    expect($invoice->payment_state->value)->toBe('VOID');

    $creditNotes = CreditNote::where('quote_id', $quote->id)->get();
    expect($creditNotes)->toHaveCount(1);
    expect((float) $creditNotes->first()->amount)->toBe(199.90);

    expect(AuditLog::where('auditable_type', ProductionJob::class)
        ->where('auditable_id', $job->id)
        ->where('event', 'production_job.return_resolved')
        ->exists())->toBeTrue();
});

it('resolves reship: clears the old consignment ref, sends the job back to IN_PRODUCTION, and a later ship books a fresh distinct number', function (): void {
    $job = returnedShippedJob('NVSGRETSHIP1');
    $oldRef = $job->shipment->consignment_ref;

    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/resolve-return", [
        'disposition' => 'reship',
    ])->assertOk()->assertJsonPath('data.state', 'IN_PRODUCTION');

    $job->refresh();
    $shipment = $job->shipment;
    expect($job->state)->toBe(JobState::InProduction)
        ->and($shipment->consignment_ref)->toBeNull()
        ->and($shipment->carrier)->toBeNull()
        ->and($shipment->label_url)->toBeNull()
        ->and($shipment->last_courier_status)->toBeNull()
        ->and($shipment->last_courier_status_at)->toBeNull();

    // A subsequent ship must book a NEW, distinct consignment_ref (the old
    // one is cleared, so the unique index + a fresh forShipment() call both work).
    $reshipped = app(ShipmentService::class)->createForJob($job->fresh());

    expect($reshipped->state)->toBe(JobState::Shipped)
        ->and($reshipped->shipment->consignment_ref)->not->toBeNull()
        ->and($reshipped->shipment->consignment_ref)->not->toBe($oldRef);
});

it('refuses to resolve a job that is not flagged returned/failed (e.g. a normal in-transit job)', function (): void {
    $job = returnedShippedJob('NVSGINTRANSIT1');
    $job->shipment->last_courier_status = 'In transit';
    $job->shipment->save();

    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/resolve-return", [
        'disposition' => 'close',
    ])->assertStatus(422);

    expect($job->fresh()->state)->toBe(JobState::Shipped);
});

it('refuses to resolve a job with no courier status at all', function (): void {
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'created_by' => $this->buyer->id,
    ]);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/core.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();
    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $queue->advance($job, JobState::InProduction);
    $job = $queue->advance($job, JobState::Shipped, 'NVSGNOFLAG001', Carrier::NinjaVan);

    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/resolve-return", [
        'disposition' => 'close',
    ])->assertStatus(422);

    expect($job->fresh()->state)->toBe(JobState::Shipped);
});

it('forbids a buyer from resolving a returned parcel', function (): void {
    $job = returnedShippedJob('NVSGBUYER0001');

    Sanctum::actingAs($this->buyer);

    $this->postJson("/api/production-jobs/{$job->id}/resolve-return", [
        'disposition' => 'close',
    ])->assertStatus(403);

    expect($job->fresh()->state)->toBe(JobState::Shipped);
});

it('validates the disposition value', function (): void {
    $job = returnedShippedJob('NVSGBADVAL0001');
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/production-jobs/{$job->id}/resolve-return", [
        'disposition' => 'not-a-real-disposition',
    ])->assertStatus(422)->assertJsonValidationErrors(['disposition']);

    expect($job->fresh()->state)->toBe(JobState::Shipped);
});

it('still refuses to cancel a READY quote through the general cancel endpoint (unaffected by the new resolution edge)', function (): void {
    $quote = Quote::factory()->create(['state' => 'READY']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertStatus(422);
    expect($quote->refresh()->state->value)->toBe('READY');
});
