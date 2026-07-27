<?php

declare(strict_types=1);

use App\Enums\Carrier;
use App\Enums\JobState;
use App\Events\OrderTrackingUpdated;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\OrderTracker;
use App\Services\QueueService;

/**
 * broadcastWith() feeds the SAME live no-reload path the initial page load
 * uses (OrderTracker::payload()). Before this fix it dropped shipments/
 * items_completed/items_total, so a buyer watching the tracker never saw the
 * tracking link or item count appear when their order shipped - only a page
 * refresh (which hits the HTTP payload) revealed them.
 */
it('includes shipments, items_completed and items_total alongside the existing fields', function (): void {
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
    $queue->advance($job, JobState::Shipped, 'NVSGNEXGE000BC0001', Carrier::NinjaVan);

    $quote->refresh();
    $expected = app(OrderTracker::class)->payload($quote);

    $broadcast = (new OrderTrackingUpdated($quote))->broadcastWith();

    expect($broadcast)->toHaveKeys(['reference', 'stage', 'stage_label', 'cancelled', 'updated_at', 'shipments', 'items_completed', 'items_total'])
        ->and($broadcast['stage'])->toBe('SHIPPED')
        ->and($broadcast['shipments'])->toBe($expected['shipments'])
        ->and($broadcast['items_completed'])->toBe($expected['items_completed'])
        ->and($broadcast['items_total'])->toBe($expected['items_total'])
        ->and($broadcast['items_completed'])->toBe(1)
        ->and($broadcast['items_total'])->toBe(1)
        ->and($broadcast['shipments'])->toHaveCount(1);
});
