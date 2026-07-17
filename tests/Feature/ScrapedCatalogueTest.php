<?php

declare(strict_types=1);

use App\Enums\PublishState;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Services\Catalogue\ScrapedCatalogueService;
use App\Services\Scraper\FixtureScraperClient;
use App\Services\Scraper\ScrapedProductData;

beforeEach(function (): void {
    seedPricing();
    $this->client = app(FixtureScraperClient::class);
    $this->service = app(ScrapedCatalogueService::class);
});

function listing(array $o = []): ScrapedProductData
{
    return new ScrapedProductData(
        sourceProductId: $o['id'] ?? 'SHOPEE-1',
        sourceUrl: 'https://shopee.example/p/1',
        name: $o['name'] ?? 'Scraped Mug',
        price: array_key_exists('price', $o) ? $o['price'] : 4.00,
        dimensions: $o['dimensions'] ?? ['l' => 90, 'w' => 80, 'h' => 95, 'unit' => 'mm'],
        weight: array_key_exists('weight', $o) ? $o['weight'] : 300,
        stockEstimate: $o['stock'] ?? 50,
        imageUrl: 'https://img.example/1.jpg',
        printable: $o['printable'] ?? true,
        sourceDead: $o['dead'] ?? false,
    );
}

it('ingests a complete item to READY_TO_APPROVE when auto-publish is off', function (): void {
    $product = $this->service->ingest(listing());

    expect($product->publish_state->value)->toBe('READY_TO_APPROVE')
        ->and($product->cannot_publish_reasons)->toBeNull();
});

it('auto-publishes a complete item when the toggle is on', function (): void {
    PricingConfig::updateOrCreate(['group' => 'catalogue', 'key' => 'auto_publish'], ['value' => true]);

    $product = $this->service->ingest(listing());

    expect($product->publish_state->value)->toBe('PUBLISHED');
});

it('blocks an incomplete item with reason tags', function (): void {
    $product = $this->service->ingest(listing(['price' => null, 'weight' => null]));

    expect($product->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->cannot_publish_reasons)->toContain('missing_price')
        ->and($product->cannot_publish_reasons)->toContain('missing_dimensions');
});

it('pulls an item from public on >threshold price drift', function (): void {
    $product = $this->service->ingest(listing(['price' => 4.00]));
    // Re-scrape now reports a 50% jump (> 10% threshold).
    $this->client->with(listing(['price' => 6.00]));

    $this->service->resync($product->fresh());

    expect($product->fresh()->publish_state->value)->toBe('CANNOT_PUBLISH')
        ->and($product->fresh()->cannot_publish_reasons)->toContain('needs_re-review');
});

it('marks a dead source CANNOT_PUBLISH', function (): void {
    $product = $this->service->ingest(listing());
    $this->client->with(listing(['dead' => true]));

    $this->service->resync($product->fresh());

    expect($product->fresh()->cannot_publish_reasons)->toContain('source_dead');
});

it('never pulls an already-published item on price drift', function (): void {
    // Owner decision: a resync must not unpublish a live listing. A drifted
    // price is reflected, but the item stays PUBLISHED (staff pull manually).
    $product = $this->service->ingest(listing(['price' => 4.00]));
    $product->update(['publish_state' => 'PUBLISHED', 'cannot_publish_reasons' => null]);
    $this->client->with(listing(['price' => 6.00])); // +50%, past the 10% threshold

    $this->service->resync($product->fresh());

    expect($product->fresh()->publish_state->value)->toBe('PUBLISHED')
        ->and($product->fresh()->cannot_publish_reasons)->toBeNull()
        ->and((float) $product->fresh()->base_cost)->toBe(6.00); // price still refreshed
});

it('never pulls an already-published item when the source blips dead', function (): void {
    $product = $this->service->ingest(listing());
    $product->update(['publish_state' => 'PUBLISHED', 'cannot_publish_reasons' => null]);
    $this->client->with(listing(['dead' => true]));

    $this->service->resync($product->fresh());

    expect($product->fresh()->publish_state->value)->toBe('PUBLISHED')
        ->and($product->fresh()->cannot_publish_reasons)->toBeNull();
});

it('regates a fixed-up product to ReadyToApprove without publishing it', function (): void {
    // auto_publish ON: regate must still NOT jump straight to Published -
    // publication stays an explicit staff decision.
    PricingConfig::updateOrCreate(['group' => 'catalogue', 'key' => 'auto_publish'], ['value' => true]);

    $product = Product::factory()->scrapedUv()->create([
        'publish_state' => 'CANNOT_PUBLISH',
        'cannot_publish_reasons' => ['missing_dimensions'],
        'base_cost' => 12.00,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10, 'unit' => 'mm'],
        'weight' => 250,
        'is_printable' => true,
        'print_method' => 'UV',
    ]);

    $service = app(ScrapedCatalogueService::class);
    $result = $service->regate($product);

    expect($result->publish_state)->toBe(PublishState::ReadyToApprove)
        ->and($result->cannot_publish_reasons)->toBeNull();
});

it('regates an incomplete product back to CannotPublish with fresh reasons', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'publish_state' => 'READY_TO_APPROVE',
        'cannot_publish_reasons' => null,
        'base_cost' => 12.00,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10, 'unit' => 'mm'],
        'weight' => null,          // → missing_dimensions
        'is_printable' => true,
        'print_method' => 'UV',
    ]);

    $result = app(ScrapedCatalogueService::class)->regate($product);

    expect($result->publish_state)->toBe(PublishState::CannotPublish)
        ->and($result->cannot_publish_reasons)->toBe(['missing_dimensions']);
});
