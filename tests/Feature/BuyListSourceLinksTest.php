<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\SupplierReorder;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
});

it('returns source_links on each reorder for the buy-list', function (): void {
    $product = Product::factory()->scrapedUv()->create([
        'source_links' => [
            ['label' => 'LocalCo', 'url' => 'https://localco.sg/mug', 'kind' => 'local', 'price' => 12.0, 'currency' => 'SGD', 'last_checked' => null],
            ['label' => 'Shopee', 'url' => 'https://shopee.sg/product/1/2', 'kind' => 'marketplace', 'price' => 9.9, 'currency' => 'SGD', 'last_checked' => null],
        ],
    ]);
    $variant = Variant::factory()->create(['product_id' => $product->id]);
    SupplierReorder::create([
        'variant_id' => $variant->id,
        'filament_id' => null,
        'sku' => null,
        'qty' => 10,
        'state' => 'DRAFT',
        'approved_by' => null,
    ]);

    Sanctum::actingAs($this->staff);
    $res = $this->getJson('/api/admin/supplier-reorders')->assertOk();

    $row = collect($res->json('data'))->firstWhere('product_id', $product->id);
    expect($row['source_links'])->toHaveCount(2)
        ->and($row['source_links'][0]['url'])->toBe('https://localco.sg/mug')
        ->and($row['source_url'])->toBe('https://localco.sg/mug');
});
