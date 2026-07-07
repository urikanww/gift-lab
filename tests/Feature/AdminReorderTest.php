<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\SupplierReorder;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

// Buy-list: surface open supplier reorder drafts and mark them received, which
// restocks the variant through the ledger.

beforeEach(function (): void {
    seedPricing();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->buyer = User::factory()->create(['role' => 'buyer']);
    $this->product = Product::factory()->create(['class' => 'CORE', 'source_url' => 'https://aff.example/blank']);
});

function makeReorder(int $stock, float $qty, string $state = 'DRAFT'): SupplierReorder
{
    $variant = Variant::factory()->create(['product_id' => test()->product->id, 'stock_on_hand' => $stock]);

    return SupplierReorder::create([
        'variant_id' => $variant->id,
        'filament_id' => null,
        'sku' => null,
        'qty' => $qty,
        'state' => $state,
        'approved_by' => null,
    ]);
}

it('lists open reorders with the affiliate source and hides received ones', function (): void {
    Sanctum::actingAs($this->staff);
    $open = makeReorder(stock: -3, qty: 10);
    makeReorder(stock: 5, qty: 8, state: 'RECEIVED');

    $res = $this->getJson('/api/admin/supplier-reorders')->assertOk();

    $res->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $open->id)
        ->assertJsonPath('data.0.stock_on_hand', -3)
        ->assertJsonPath('data.0.source_url', 'https://aff.example/blank')
        ->assertJsonPath('data.0.kind', 'variant');
});

it('marks a reorder received and restocks the variant through the ledger', function (): void {
    Sanctum::actingAs($this->staff);
    $reorder = makeReorder(stock: -3, qty: 10);

    $this->postJson("/api/admin/supplier-reorders/{$reorder->id}/receive")
        ->assertOk()->assertJsonPath('data.state', 'RECEIVED');

    // -3 backorder deficit + 10 received = 7 on hand, via a RESTOCK movement.
    expect($reorder->variant->fresh()->stock_on_hand)->toBe(7);
    $this->assertDatabaseHas('stock_movements', [
        'variant_id' => $reorder->variant_id,
        'delta' => 10,
        'reason' => 'RESTOCK',
    ]);
    $this->assertDatabaseHas('audit_logs', ['event' => 'supplier_reorder.received']);
});

it('rejects receiving a reorder twice', function (): void {
    Sanctum::actingAs($this->staff);
    $reorder = makeReorder(stock: 0, qty: 5, state: 'RECEIVED');

    $this->postJson("/api/admin/supplier-reorders/{$reorder->id}/receive")->assertStatus(422);
});

it('blocks buyers from the buy-list', function (): void {
    Sanctum::actingAs($this->buyer);
    $reorder = makeReorder(stock: 0, qty: 5);

    $this->getJson('/api/admin/supplier-reorders')->assertForbidden();
    $this->postJson("/api/admin/supplier-reorders/{$reorder->id}/receive")->assertForbidden();
});
