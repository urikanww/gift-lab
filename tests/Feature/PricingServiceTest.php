<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\PricingService;

beforeEach(function (): void {
    seedPricing();
    $this->pricing = app(PricingService::class);
});

it('prices a unit as marged blank cost plus per-method print cost', function (): void {
    // base 10 + 35% margin = 13.50; + UV print 1.50 = 15.00 (below bulk qty).
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    expect($this->pricing->unitPrice($product, null, 1))->toBe(15.00);
});

it('applies the bulk discount at or above the configured threshold', function (): void {
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    $unit = $this->pricing->unitPrice($product, null, 50); // bulk_qty default 50
    // 15.00 * (1 - 10%) = 13.50
    expect($unit)->toBe(13.50);
});

it('computes delivery from the weight table', function (): void {
    // <=1000g tier => 5.00
    expect($this->pricing->deliveryFor(800.0))->toBe(5.00)
        ->and($this->pricing->deliveryFor(4000.0))->toBe(12.00)
        ->and($this->pricing->deliveryFor(999999.0))->toBe(60.00);
});

it('enforces the margin floor over landed cost', function (): void {
    // floor 12% over landed 10.00 => 11.20 minimum.
    expect($this->pricing->isAboveMarginFloor(11.20, 10.00))->toBeTrue()
        ->and($this->pricing->isAboveMarginFloor(11.19, 10.00))->toBeFalse();
});

it('prices a MODEL_3D unit from filament grams and machine time, not base cost', function (): void {
    // landed = 50g × 0.06 + 50g × 2.0 min/g × 0.08/min = 3.00 + 8.00 = 11.00
    // unit = 11.00 × 1.35 margin = 14.85 (flat FDM per-unit fee must NOT stack).
    $product = Product::factory()->create([
        'class' => 'MODEL_3D',
        'base_cost' => 0,
        'print_method' => 'FDM',
        'est_grams' => 50,
    ]);

    expect($this->pricing->unitPrice($product, null, 1))->toBe(14.85)
        ->and($this->pricing->landedCost($product, null))->toBe(11.00);
});

it('charges the heaviest delivery tier when weight exceeds every tier', function (): void {
    // Misconfigured table without a null-max catch-all must not fall through
    // to free shipping.
    \App\Models\PricingConfig::updateOrCreate(
        ['group' => 'delivery', 'key' => 'table'],
        ['value' => [
            ['max_weight_g' => 1000, 'price' => 5.00],
            ['max_weight_g' => 5000, 'price' => 12.00],
        ], 'label' => 'Delivery', 'is_money' => true, 'currency' => 'SGD'],
    );

    expect($this->pricing->deliveryFor(99999.0))->toBe(12.00);
});
