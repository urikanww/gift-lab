<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('derives source_url from the first local link on save', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_url' => null,
        'source_links' => [
            ['label' => 'Shopee', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null],
            ['label' => 'LocalCo', 'url' => 'https://localco.sg/mug', 'kind' => 'local', 'price' => 12.0, 'currency' => 'SGD', 'last_checked' => null],
        ],
    ]);

    expect($product->fresh()->source_url)->toBe('https://localco.sg/mug');
});

it('falls back to the first link when no local link exists', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_url' => null,
        'source_links' => [
            ['label' => 'Shopee', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null],
        ],
    ]);

    expect($product->fresh()->source_url)->toBe('https://shopee.sg/product/1/2');
});

it('casts source_links to an array', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_links' => [['label' => 'X', 'url' => 'https://x.sg/1', 'kind' => 'local', 'price' => 1.0, 'currency' => 'SGD', 'last_checked' => null]],
    ]);

    expect($product->fresh()->source_links)->toBeArray()->toHaveCount(1);
});
