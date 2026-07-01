<?php

declare(strict_types=1);

use App\Models\Product;

it('exposes only published products on the public catalogue', function (): void {
    Product::factory()->create(['name' => 'Published Mug', 'publish_state' => 'PUBLISHED']);
    Product::factory()->create(['name' => 'Draft Mug', 'publish_state' => 'PENDING']);

    $response = $this->getJson('/api/catalogue');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name');
    expect($names)->toContain('Published Mug')
        ->and($names)->not->toContain('Draft Mug');
});

it('404s an unpublished product detail', function (): void {
    $product = Product::factory()->create(['publish_state' => 'PENDING']);

    $this->getJson("/api/catalogue/{$product->id}")->assertNotFound();
});

it('returns a live price estimate without an account', function (): void {
    seedPricing();
    $product = Product::factory()->create(['base_cost' => 10, 'publish_state' => 'PUBLISHED', 'print_method' => 'UV']);

    $response = $this->postJson('/api/price-estimate', [
        'line_items' => [
            ['product_id' => $product->id, 'variant_id' => null, 'qty' => 5, 'has_customization' => true],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('currency', 'SGD');
    expect((float) $response->json('total'))->toBeGreaterThan(0.0);
});
