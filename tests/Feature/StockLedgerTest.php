<?php

declare(strict_types=1);

use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\StockLedger;
use Laravel\Sanctum\Sanctum;

// Stock management foundation: append-only ledger + cached on-hand, plus the
// allow_backorder ("on-demand") product flag.

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->buyer = User::factory()->create(['role' => 'buyer']);
});

it('records a movement and keeps stock_on_hand as the running sum', function (): void {
    $ledger = app(StockLedger::class);
    $variant = Variant::factory()->create(['stock_on_hand' => 0]);

    $ledger->record($variant, 10, StockMovementReason::Restock);
    $ledger->record($variant, -3, StockMovementReason::Sale);

    expect($variant->fresh()->stock_on_hand)->toBe(7);
    $this->assertDatabaseHas('stock_movements', ['variant_id' => $variant->id, 'delta' => 10, 'reason' => 'RESTOCK']);
    $this->assertDatabaseHas('stock_movements', ['variant_id' => $variant->id, 'delta' => -3, 'reason' => 'SALE']);

    // On-hand must equal SUM(delta) - the column is a cache of the ledger.
    $sum = $variant->movements()->sum('delta');
    expect((int) $sum)->toBe($variant->fresh()->stock_on_hand);
});

it('lets a backorder movement drive on-hand negative (the procurement worklist)', function (): void {
    $ledger = app(StockLedger::class);
    $variant = Variant::factory()->create(['stock_on_hand' => 1]);

    $ledger->record($variant, -3, StockMovementReason::Sale);

    expect($variant->fresh()->stock_on_hand)->toBe(-2);
});

it('seeds an INIT movement when staff create a variant with stock', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['class' => 'CORE']);

    $variantId = $this->postJson("/api/admin/products/{$product->id}/variants", [
        'attributes' => ['color' => 'Silver'],
        'stock_on_hand' => 500,
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('variants', ['id' => $variantId, 'stock_on_hand' => 500]);
    $this->assertDatabaseHas('stock_movements', ['variant_id' => $variantId, 'delta' => 500, 'reason' => 'INIT']);
});

it('turns a manual stock edit into a signed ADJUST movement', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create();
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 5]);

    $this->patchJson("/api/admin/variants/{$variant->id}", ['stock_on_hand' => 400])
        ->assertOk()->assertJsonPath('data.stock_on_hand', 400);

    // 5 -> 400 is a +395 ADJUST, not a silent overwrite.
    $this->assertDatabaseHas('stock_movements', ['variant_id' => $variant->id, 'delta' => 395, 'reason' => 'ADJUST']);
    expect($variant->fresh()->stock_on_hand)->toBe(400);
});

it('does not write a movement when a variant edit leaves stock unchanged', function (): void {
    Sanctum::actingAs($this->staff);
    $variant = Variant::factory()->create(['stock_on_hand' => 5, 'price_delta' => 1.00]);

    $this->patchJson("/api/admin/variants/{$variant->id}", ['price_delta' => 2.00])->assertOk();

    expect($variant->movements()->count())->toBe(0);
});

it('exposes and persists the allow_backorder flag on a product', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['class' => 'CORE']);

    $this->getJson("/api/admin/products/{$product->id}")
        ->assertOk()->assertJsonPath('data.allow_backorder', false);

    $this->patchJson("/api/admin/products/{$product->id}", ['allow_backorder' => true])
        ->assertOk()->assertJsonPath('data.allow_backorder', true);

    $this->assertDatabaseHas('products', ['id' => $product->id, 'allow_backorder' => true]);
});
