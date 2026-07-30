<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\OrderTracker;
use App\Services\QueueService;

it('builds a PII-free payload for a quote', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);

    $payload = app(OrderTracker::class)->payload($quote->fresh());

    expect($payload['reference'])->toBe($quote->reference)
        ->and($payload['stage'])->toBe('ACTION_REQUIRED')
        ->and($payload['stage_label'])->toBe('Awaiting your approval')
        ->and($payload)->not->toHaveKeys(['total', 'subtotal', 'notes']);
});

it('exposes needed_by and item counts', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create([
        'company_id' => $company->id,
        'state' => 'PROCURING',
        'needed_by' => '2026-08-15',
    ]);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'qty' => 5,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();

    // Before shipping: 1 line, 0 completed.
    $before = app(OrderTracker::class)->payload($quote->fresh());
    expect($before['needed_by'])->toBe('2026-08-15')
        ->and($before['items_total'])->toBe(1)
        ->and($before['items_completed'])->toBe(0);

    // Ship then close the job: line is completed.
    $queue->advance($job, JobState::InProduction);
    $queue->advance($job, JobState::Shipped, 'SP123456789SG');
    $after = app(OrderTracker::class)->payload($quote->fresh());
    expect($after['items_completed'])->toBe(1);
});

it('surfaces the live courier status on a shipped job', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $queue->advance($job, JobState::InProduction);
    $queue->advance($job, JobState::Shipped, 'NVSGNEXGE000TRK001', Carrier::NinjaVan);

    $job->refresh();
    $job->last_courier_status = 'Out for delivery';
    $job->last_courier_status_at = now();
    $job->save();

    $shipment = app(OrderTracker::class)->payload($quote->fresh())['shipments'][0];

    expect($shipment['status'])->toBe('Out for delivery')
        ->and($shipment['status_at'])->not->toBeNull()
        ->and($shipment['delivered_at'])->toBeNull();
});

it('surfaces delivered_at once the job has been delivered', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
    ]);
    Proof::factory()->forLine($line)->approved()->create();

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $queue->advance($job, JobState::InProduction);
    $queue->advance($job, JobState::Shipped, 'NVSGNEXGE000TRK002', Carrier::NinjaVan);

    $job->refresh();
    $job->last_courier_status = 'Delivered';
    $job->last_courier_status_at = now();
    $job->delivered_at = now();
    $job->save();
    $queue->advance($job->fresh(), JobState::Closed);

    $shipment = app(OrderTracker::class)->payload($quote->fresh())['shipments'][0];

    expect($shipment['delivered_at'])->not->toBeNull()
        ->and($shipment['status'])->toBe('Delivered');
});
