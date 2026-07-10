<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Services\OrderTracker;
use App\Enums\JobState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Services\QueueService;

it('builds a PII-free payload for a quote', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);

    $payload = app(OrderTracker::class)->payload($quote->fresh());

    expect($payload['reference'])->toBe($quote->tracking_code)
        ->and($payload['stage'])->toBe('REVIEW')
        ->and($payload['stage_label'])->toBe('In review')
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
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 5]);

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
