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

it('builds a tracking url from the ref, url-encoding it', function (): void {
    expect(Carrier::NinjaVan->trackingUrl('NV 12/34'))
        ->toContain('NV%2012%2F34')
        ->and(Carrier::Other->trackingUrl('X'))->toBeNull()
        ->and(Carrier::SingPost->label())->toBe('SingPost');
});

it('surfaces a carrier tracking link on a shipped order', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'qty' => 3]);

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();
    $queue->advance($job, JobState::InProduction);
    $queue->advance($job, JobState::Shipped, 'NV11223344', Carrier::NinjaVan);

    $shipments = app(OrderTracker::class)->payload($quote->fresh())['shipments'];

    expect($shipments)->toHaveCount(1)
        ->and($shipments[0]['carrier_label'])->toBe('Ninja Van')
        ->and($shipments[0]['ref'])->toBe('NV11223344')
        ->and($shipments[0]['tracking_url'])->toContain('NV11223344');
});
