<?php

declare(strict_types=1);

use App\Models\GiftIdeaFeature;
use App\Services\Scraper\FixtureScraperClient;
use App\Services\Scraper\ScrapedProductData;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    $this->client = app(FixtureScraperClient::class);
});

function featureListing(string $id, ?float $price, bool $dead = false): ScrapedProductData
{
    return new ScrapedProductData(
        sourceProductId: $id, sourceUrl: "https://shopee.sg/p/{$id}", name: 'Mug',
        price: $price, dimensions: null, weight: null, stockEstimate: null,
        imageUrl: null, printable: false, sourceDead: $dead,
    );
}

it('updates price on refresh', function (): void {
    $f = GiftIdeaFeature::factory()->create(['source_product_id' => 'S_1', 'price' => 5.00]);
    $this->client->with(featureListing('S_1', 8.00));

    $this->artisan('giftideas:refresh')->assertSuccessful();

    expect((float) $f->fresh()->price)->toBe(8.00);
});

it('prunes a dead featured source', function (): void {
    $f = GiftIdeaFeature::factory()->create(['source_product_id' => 'S_2']);
    $this->client->with(featureListing('S_2', null, dead: true));

    $this->artisan('giftideas:refresh')->assertSuccessful();

    expect(GiftIdeaFeature::where('source_product_id', 'S_2')->exists())->toBeFalse();
});
