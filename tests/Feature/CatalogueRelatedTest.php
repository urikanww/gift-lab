<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;

/**
 * The public PDP "You might also like" endpoint and its relevance tiers:
 * frequently-bought-together (learned) → same category → complements → fill.
 */
function publishedProduct(array $attrs = []): Product
{
    return Product::factory()->create(array_merge(['publish_state' => 'PUBLISHED'], $attrs));
}

function quoteWithLines(int ...$productIds): void
{
    $quote = Quote::factory()->create(['company_id' => Company::factory()->create()->id]);
    foreach ($productIds as $pid) {
        LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $pid]);
    }
}

it('ranks frequently-bought-together products first, by co-occurrence count', function (): void {
    $anchor = publishedProduct(['category' => 'drinkware', 'name' => 'Anchor Mug']);
    $together = publishedProduct(['category' => 'home', 'name' => 'Together Coaster']);
    $once = publishedProduct(['category' => 'home', 'name' => 'Once Frame']);
    $sameCat = publishedProduct(['category' => 'drinkware', 'name' => 'Zzz Tumbler']);

    // anchor co-appears with `together` in 2 quotes, with `once` in 1.
    quoteWithLines($anchor->id, $together->id);
    quoteWithLines($anchor->id, $together->id);
    quoteWithLines($anchor->id, $once->id);

    $ids = collect($this->getJson("/api/catalogue/{$anchor->id}/related")->assertOk()->json('data'))
        ->pluck('id')->all();

    // Learned tier leads, most-together first; same-category still surfaces below.
    expect(array_slice($ids, 0, 2))->toBe([$together->id, $once->id]);
    expect($ids)->toContain($sameCat->id);
});

it('excludes unpublished co-ordered products from the learned tier', function (): void {
    $anchor = publishedProduct(['category' => 'drinkware', 'name' => 'Anchor Mug']);
    $hidden = publishedProduct(['category' => 'home', 'name' => 'Hidden Coaster', 'publish_state' => 'READY_TO_APPROVE']);

    quoteWithLines($anchor->id, $hidden->id);

    $ids = collect($this->getJson("/api/catalogue/{$anchor->id}/related")->assertOk()->json('data'))
        ->pluck('id')->all();

    expect($ids)->not->toContain($hidden->id);
});

it('falls back to same-category related when there is no order history', function (): void {
    $anchor = publishedProduct(['category' => 'bags', 'name' => 'Anchor Tote']);
    $sameCat = publishedProduct(['category' => 'bags', 'name' => 'Buddy Pouch']);

    $ids = collect($this->getJson("/api/catalogue/{$anchor->id}/related")->assertOk()->json('data'))
        ->pluck('id')->all();

    expect($ids)->toContain($sameCat->id);
});
