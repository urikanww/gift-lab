<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Variant;

// M10: the estimate must say WHICH line is unavailable, not fail the whole batch
// with no signal - a cart holding one since-unpublished item otherwise can't
// tell the buyer what to remove.
it('reports which line is unavailable instead of a blanket error', function (): void {
    seedPricing();
    $published = Product::factory()->create(['publish_state' => 'PUBLISHED']);
    Variant::factory()->create(['product_id' => $published->id]);
    $draft = Product::factory()->create(['publish_state' => 'PENDING']);

    $this->postJson('/api/price-estimate', [
        'line_items' => [
            ['product_id' => $published->id, 'qty' => 5],
            ['product_id' => $draft->id, 'qty' => 2],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('unavailable.0.index', 1)
        ->assertJsonPath('unavailable.0.product_id', $draft->id);
});

it('estimates normally when every line is available', function (): void {
    seedPricing();
    $product = Product::factory()->create(['publish_state' => 'PUBLISHED', 'base_cost' => 10]);
    Variant::factory()->create(['product_id' => $product->id]);

    $this->postJson('/api/price-estimate', [
        'line_items' => [['product_id' => $product->id, 'qty' => 5]],
    ])->assertOk()->assertJsonPath('currency', 'SGD');
});
