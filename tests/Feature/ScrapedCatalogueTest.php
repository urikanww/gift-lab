<?php

declare(strict_types=1);

use App\Models\PricingConfig;
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
