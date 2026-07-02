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

it('generates a stable, unique slug and resolves the detail page by it', function (): void {
    $first = Product::factory()->create(['name' => 'Ceramic Mug 350ml', 'publish_state' => 'PUBLISHED']);
    $second = Product::factory()->create(['name' => 'Ceramic Mug 350ml', 'publish_state' => 'PUBLISHED']);

    expect($first->slug)->toBe('ceramic-mug-350ml')
        ->and($second->slug)->toBe('ceramic-mug-350ml-2');

    // Slug survives a rename — shared links never break.
    $first->update(['name' => 'Renamed Mug']);
    expect($first->fresh()->slug)->toBe('ceramic-mug-350ml');

    $this->getJson('/api/catalogue/ceramic-mug-350ml')
        ->assertOk()
        ->assertJsonPath('data.id', $first->id)
        ->assertJsonPath('data.slug', 'ceramic-mug-350ml');
});

it('still resolves a product detail by numeric id (legacy links)', function (): void {
    $product = Product::factory()->create(['publish_state' => 'PUBLISHED']);

    $this->getJson("/api/catalogue/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $product->id);
});

it('404s an unknown slug', function (): void {
    $this->getJson('/api/catalogue/no-such-thing')->assertNotFound();
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
