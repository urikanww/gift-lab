<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

// Audit E1/D7/E2 (superadmin pricing editor) + E4 (CORE product/variant CRUD
// and the variant-less-CORE quote guard).

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->superadmin = User::factory()->create(['role' => 'superadmin', 'company_id' => null]);
});

it('lets the superadmin list and edit a pricing config without a deploy', function (): void {
    Sanctum::actingAs($this->superadmin);

    $this->getJson('/api/admin/pricing-configs')->assertOk()
        ->assertJsonFragment(['group' => 'margin', 'key' => 'floor_pct']);

    $config = PricingConfig::where('group', 'fee')->where('key', 'customization_by_size')->firstOrFail();

    $this->patchJson("/api/admin/pricing-configs/{$config->id}", [
        'value' => ['S' => 0.00, 'M' => 1.25, 'L' => 0.90],
    ])->assertOk()->assertJsonPath('data.value.M', 1.25);

    // The quote engine reads the new value immediately (cache is busted on save).
    PricingConfig::flushMemo();
    expect(PricingConfig::value('fee', 'customization_by_size')['M'])->toBe(1.25);
    $this->assertDatabaseHas('audit_logs', ['event' => 'pricing_config.updated']);
});

it('reflects an edited size surcharge in the next price estimate', function (): void {
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);

    $estimateFor = fn (): float => (float) $this->postJson('/api/price-estimate', [
        'line_items' => [[
            'product_id' => $product->id, 'qty' => 10,
            'has_customization' => true, 'logo_size' => 'M',
        ]],
    ])->assertOk()->json('lines.0.line_total');

    $before = $estimateFor();

    Sanctum::actingAs($this->superadmin);
    $config = PricingConfig::where('group', 'fee')->where('key', 'customization_by_size')->firstOrFail();
    $this->patchJson("/api/admin/pricing-configs/{$config->id}", [
        'value' => ['S' => 0.00, 'M' => 2.40, 'L' => 0.90],
    ])->assertOk();
    PricingConfig::flushMemo();

    // M surcharge went 0.40 -> 2.40 (+2.00/unit × 10 units).
    expect($estimateFor())->toBe(round($before + 20.0, 2));
});

it('blocks staff (non-superadmin) and buyers from the pricing editor', function (): void {
    $config = PricingConfig::query()->firstOrFail();

    Sanctum::actingAs($this->staff);
    $this->getJson('/api/admin/pricing-configs')->assertForbidden();
    $this->patchJson("/api/admin/pricing-configs/{$config->id}", ['value' => 1])->assertForbidden();

    Sanctum::actingAs($this->buyer);
    $this->getJson('/api/admin/pricing-configs')->assertForbidden();
});

it('lets staff create a CORE product with a variant and stock, then a buyer quotes it', function (): void {
    Sanctum::actingAs($this->staff);

    $productId = $this->postJson('/api/admin/products', [
        'name' => 'Omega Tumbler',
        'description' => 'Stainless steel tumbler.',
        'base_cost' => 7.50,
        'weight' => 250,
        'dimensions' => ['l' => 90, 'w' => 90, 'h' => 180],
        'print_method' => 'UV',
        'stock_mode' => 'STOCKED',
        'publish_state' => 'PUBLISHED',
    ])->assertCreated()->json('data.id');

    $variantId = $this->postJson("/api/admin/products/{$productId}/variants", [
        'attributes' => ['color' => 'Silver'],
        'stock_on_hand' => 500,
        'reorder_threshold' => 50,
        'price_delta' => 0.50,
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs($this->buyer);
    $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [[
            'product_id' => $productId,
            'variant_id' => $variantId,
            'qty' => 10,
        ]],
    ])->assertCreated();
});

it('lets staff adjust variant stock and product price without seeders', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10]);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 5]);

    $this->patchJson("/api/admin/products/{$product->id}", ['base_cost' => 12.50])
        ->assertOk()->assertJsonPath('data.base_cost', '12.50');

    $this->patchJson("/api/admin/variants/{$variant->id}", ['stock_on_hand' => 400])
        ->assertOk()->assertJsonPath('data.stock_on_hand', 400);
});

it('blocks buyers from the product admin endpoints', function (): void {
    Sanctum::actingAs($this->buyer);
    $this->postJson('/api/admin/products', ['name' => 'X'])->assertForbidden();
});

it('rejects quoting a CORE product that has no variants (E4 interim guard)', function (): void {
    Sanctum::actingAs($this->buyer);
    $variantless = Product::factory()->create(['publish_state' => 'PUBLISHED']);

    $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [[
            'product_id' => $variantless->id,
            'variant_id' => null,
            'qty' => 5,
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors('line_items.0.product_id');
});

it('lists every product class in the manager, not just CORE', function (): void {
    Sanctum::actingAs($this->staff);

    $core = Product::factory()->create(['name' => 'Core Blank', 'class' => 'CORE']);
    $model = Product::factory()->create(['name' => 'Printed Vase', 'class' => 'MODEL_3D']);

    $this->getJson('/api/admin/products')
        ->assertOk()
        ->assertJsonFragment(['id' => $core->id])
        ->assertJsonFragment(['id' => $model->id]);

    // Class filter narrows the list.
    $this->getJson('/api/admin/products?class=MODEL_3D')
        ->assertOk()
        ->assertJsonFragment(['id' => $model->id])
        ->assertJsonMissing(['id' => $core->id]);
});

it('lets staff edit a non-CORE product (the CORE-only guard is gone)', function (): void {
    Sanctum::actingAs($this->staff);
    $model = Product::factory()->create(['class' => 'MODEL_3D', 'base_cost' => 5]);

    $this->patchJson("/api/admin/products/{$model->id}", ['base_cost' => 9.90])
        ->assertOk()->assertJsonPath('data.base_cost', '9.90');
});

it('exposes a licence compliance tier on each product', function (): void {
    Sanctum::actingAs($this->staff);
    $risky = Product::factory()->create(['class' => 'MODEL_3D', 'license' => 'CC_BY_NC']);
    $core = Product::factory()->create(['class' => 'CORE', 'license' => null]);

    $data = collect($this->getJson('/api/admin/products')->assertOk()->json('data'));
    expect($data->firstWhere('id', $risky->id)['license_tier'])->toBe('high_risk')
        ->and($data->firstWhere('id', $core->id)['license_tier'])->toBe('standard');
});

it('filters the product list by licence tier', function (): void {
    Sanctum::actingAs($this->staff);
    $risky = Product::factory()->create(['class' => 'MODEL_3D', 'license' => 'CC_BY_ND']);
    $extended = Product::factory()->create(['class' => 'MODEL_3D', 'license' => 'CC_BY_SA']);
    $core = Product::factory()->create(['class' => 'CORE', 'license' => null]);

    $highRisk = collect($this->getJson('/api/admin/products?license_tier=high_risk')->assertOk()->json('data'));
    expect($highRisk->pluck('id'))->toContain($risky->id)
        ->not->toContain($extended->id)
        ->not->toContain($core->id);

    $standard = collect($this->getJson('/api/admin/products?license_tier=standard')->assertOk()->json('data'));
    expect($standard->pluck('id'))->toContain($core->id)->not->toContain($risky->id);
});

it('lets staff archive a product and restore it (soft delete + restore)', function (): void {
    Sanctum::actingAs($this->staff);
    $model = Product::factory()->create(['name' => 'Printed Vase', 'class' => 'MODEL_3D', 'publish_state' => 'PUBLISHED']);

    // Archive → soft delete, drops from the active list.
    $this->deleteJson("/api/admin/products/{$model->id}")
        ->assertOk()->assertJsonPath('data.archived', true);
    $this->assertSoftDeleted('products', ['id' => $model->id]);

    $this->getJson('/api/admin/products?status=active')
        ->assertOk()->assertJsonMissing(['id' => $model->id]);

    // Visible only under the archived filter.
    $this->getJson('/api/admin/products?status=archived')
        ->assertOk()->assertJsonFragment(['id' => $model->id, 'archived' => true]);

    // Gone from the public storefront while archived.
    $this->getJson('/api/catalogue')->assertOk()->assertJsonMissing(['id' => $model->id]);

    // Restore → back to active.
    $this->postJson("/api/admin/products/{$model->id}/restore")
        ->assertOk()->assertJsonPath('data.archived', false);
    $this->assertDatabaseHas('products', ['id' => $model->id, 'deleted_at' => null]);

    $this->assertDatabaseHas('audit_logs', ['event' => 'product.archived']);
    $this->assertDatabaseHas('audit_logs', ['event' => 'product.restored']);
});
