<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Enums\QuoteState;
use App\Models\AuditLog;
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
