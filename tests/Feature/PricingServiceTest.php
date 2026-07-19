<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Variant;
use App\Services\PricingService;

beforeEach(function (): void {
    seedPricing();
    $this->pricing = app(PricingService::class);
});

it('prices a unit as marged blank cost plus per-method print cost', function (): void {
    // base 10 + 50% margin = 15.00; + UV print 1.50 = 16.50 (below bulk qty).
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    expect($this->pricing->unitPrice($product, null, 1))->toBe(16.50);
});

it('applies the bulk discount at or above the configured threshold', function (): void {
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    $unit = $this->pricing->unitPrice($product, null, 50); // bulk_qty default 50
    // 16.50 * (1 - 10%) = 14.85
    expect($unit)->toBe(14.85);
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
    // landed = 50g × 0.05 + 50g × 2.0 min/g × 0.08/min = 2.50 + 8.00 = 10.50
    // unit = 10.50 × 1.50 margin = 15.75 (flat FDM per-unit fee must NOT stack).
    $product = Product::factory()->create([
        'class' => 'MODEL_3D',
        'base_cost' => 0,
        'print_method' => 'FDM',
        'est_grams' => 50,
    ]);

    expect($this->pricing->unitPrice($product, null, 1))->toBe(15.75)
        ->and($this->pricing->landedCost($product, null))->toBe(10.50);
});

it('adds a per-unit logo surcharge by size band on customized lines', function (): void {
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'weight' => 0]);

    $line = fn (?string $size): array => [
        'product' => $product, 'variant' => null, 'qty' => 50,
        'has_customization' => true, 'logo_size' => $size,
    ];

    // unit @ qty 50 = 14.85 → line base 742.50 + flat 8.00 = 750.50 (no band).
    $none = $this->pricing->quoteTotals([$line(null)]);
    $medium = $this->pricing->quoteTotals([$line('M')]);
    $large = $this->pricing->quoteTotals([$line('L')]);

    // Seeded surcharge: S 0.00, M 0.40, L 0.90 per unit × 50 pcs.
    expect($none['lines'][0]['line_total'])->toBe(750.50)
        ->and($medium['lines'][0]['line_total'])->toBe(770.50)  // +0.40 × 50
        ->and($large['lines'][0]['line_total'])->toBe(795.50);   // +0.90 × 50
});

// Audit D9/D10: logo + name/text combine additively - flat fee + per-unit
// size surcharge + per-unit text fee (fee.customization_per_unit).
it('prices logo and text personalisation additively', function (): void {
    \App\Models\PricingConfig::updateOrCreate(
        ['group' => 'fee', 'key' => 'customization_per_unit'],
        ['value' => 0.30, 'label' => 'Per-unit personalisation fee', 'is_money' => true, 'currency' => 'SGD'],
    );
    \App\Models\PricingConfig::flushMemo();

    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'weight' => 0]);

    $line = fn (bool $text, ?string $size): array => [
        'product' => $product, 'variant' => null, 'qty' => 50,
        'has_customization' => true, 'logo_size' => $size, 'has_text' => $text,
    ];

    // Base 742.50 + flat 8.00 = 750.50; M adds 0.40×50 = 20; text adds 0.30×50 = 15.
    $logoOnly = $this->pricing->quoteTotals([$line(false, 'M')]);
    $textOnly = $this->pricing->quoteTotals([$line(true, null)]);
    $both = $this->pricing->quoteTotals([$line(true, 'M')]);

    expect($logoOnly['lines'][0]['line_total'])->toBe(770.50)
        ->and($textOnly['lines'][0]['line_total'])->toBe(765.50)
        ->and($both['lines'][0]['line_total'])->toBe(785.50); // additive: 750.50 + 20 + 15
});

it('never surcharges a blank (uncustomized) line even with a size present', function (): void {
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'weight' => 0]);

    $totals = $this->pricing->quoteTotals([[
        'product' => $product, 'variant' => null, 'qty' => 50,
        'has_customization' => false, 'logo_size' => 'L',
    ]]);

    // 14.85 × 50 = 742.50, no fees at all.
    expect($totals['lines'][0]['line_total'])->toBe(742.50);
});

// Superadmin price override: a fixed per-unit price replaces the whole dynamic
// computation (landed + margin + print + bulk). Variant delta still adds on top;
// bulk discount is skipped; the override may sit below landed cost.
it('uses the price override as the unit price instead of the dynamic computation', function (): void {
    // Dynamic would be 16.50; override pins it at 3.00 (deliberately below cost).
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'price_override' => 3.00,
    ]);

    expect($this->pricing->unitPrice($product, null, 1))->toBe(3.00);
});

it('adds the variant price delta on top of the price override', function (): void {
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'price_override' => 3.00,
    ]);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'price_delta' => 2]);

    expect($this->pricing->unitPrice($product, $variant, 1))->toBe(5.00);
});

it('skips the bulk discount when a price override is set', function (): void {
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'price_override' => 3.00,
    ]);

    // qty 50 is at the bulk threshold, but the override is flat - no discount.
    expect($this->pricing->unitPrice($product, null, 50))->toBe(3.00);
});

it('flags the override and zeroes derived components in the breakdown', function (): void {
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'price_override' => 3.00,
    ]);

    $bd = $this->pricing->unitPriceBreakdown($product, null, 50);

    expect($bd['unit_price'])->toBe(3.00)
        ->and($bd['overridden'])->toBeTrue()
        ->and($bd['price_override'])->toBe(3.00)
        ->and($bd['margin'])->toBe(0.0)
        ->and($bd['bulk_discount'])->toBe(0.0)
        ->and($bd['print_per_unit'])->toBe(0.0)
        // landed cost is still computed for reference (10 base, no margin here).
        ->and($bd['landed_cost'])->toBe(10.00);
});

it('uses the override in a full quote total while still charging dynamic delivery', function (): void {
    // Zero weight + no dimensions → smallest delivery tier; the point is that the
    // override drives the line total but delivery is still the weight-based figure.
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'price_override' => 4.00,
        'weight' => 0, 'dimensions' => null,
    ]);

    $totals = $this->pricing->quoteTotals([[
        'product' => $product, 'variant' => null, 'qty' => 10,
        'has_customization' => false,
    ]]);

    // 4.00 × 10 = 40.00 line (override, no bulk); delivery is dynamic on weight
    // and stacks on the subtotal (which also carries the flat setup fee).
    $delivery = $this->pricing->deliveryFor(0.0);
    expect($totals['lines'][0]['line_total'])->toBe(40.00)
        ->and($totals['delivery'])->toBe($delivery)
        ->and($totals['total'])->toBe(round($totals['subtotal'] + $delivery, 2));
});

it('flags the delivery estimate unreliable when a line has placeholder weight and dimensions', function (): void {
    // Mirrors scraped-catalogue reality: 0.5 g "weight" + a 1 cm cube collapse
    // the chargeable weight to ~0, so the derived tier would understate the fee.
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV',
        'weight' => 0.5, 'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10, 'unit' => 'mm'],
    ]);

    $totals = $this->pricing->quoteTotals([[
        'product' => $product, 'variant' => null, 'qty' => 50,
        'has_customization' => false,
    ]]);

    expect($totals['delivery_reliable'])->toBeFalse();
});

it('flags the delivery estimate reliable when weight clears the trust floor', function (): void {
    $product = Product::factory()->create([
        'base_cost' => 10, 'print_method' => 'UV', 'weight' => 200, 'dimensions' => null,
    ]);

    $totals = $this->pricing->quoteTotals([[
        'product' => $product, 'variant' => null, 'qty' => 3,
        'has_customization' => false,
    ]]);

    expect($totals['delivery_reliable'])->toBeTrue();
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
