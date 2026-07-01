<?php

declare(strict_types=1);

use App\Events\LineItemAwaitingReconfirm;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\ProcurementManager;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $this->manager = app(ProcurementManager::class);
});

function makeLine(int $stock, int $qty): LineItem
{
    $variant = Variant::factory()->create(['product_id' => test()->product->id, 'stock_on_hand' => $stock]);
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);

    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => test()->product->id,
        'variant_id' => $variant->id,
        'qty' => $qty,
        'unit_price' => 15.00,
        'line_state' => 'PENDING',
    ]);
}

it('procures a CORE line, decrements stock, and marks it ready', function (): void {
    $line = makeLine(stock: 100, qty: 5);

    $this->manager->procureLine($line->load('product', 'variant'));

    expect($line->fresh()->line_state->value)->toBe('READY')
        ->and($line->variant->fresh()->stock_on_hand)->toBe(95);
});

it('flags a shortfall as awaiting reconfirm and broadcasts', function (): void {
    Event::fake([LineItemAwaitingReconfirm::class]);
    $line = makeLine(stock: 2, qty: 5);

    $this->manager->procureLine($line->load('product', 'variant'));

    expect($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM')
        ->and($line->fresh()->procured_qty)->toBe(2);
    Event::assertDispatched(LineItemAwaitingReconfirm::class);
});

it('writes a stock re-check audit entry on shortfall', function (): void {
    Event::fake([LineItemAwaitingReconfirm::class]);
    $line = makeLine(stock: 0, qty: 4);

    $this->manager->procureLine($line->load('product', 'variant'));

    $this->assertDatabaseHas('audit_logs', ['event' => 'stock.rechecked']);
});

it('rejects a quote amendment that breaks the margin floor', function (): void {
    Sanctum::actingAs($this->staff);
    // Landed cost = base_cost (+ variant delta). Force a known base.
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => 2,
        'unit_price' => 15.00,
    ]);

    // Floor 12% over landed 10.00 => min 11.20; propose 9.00 => rejected.
    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $line->id, 'unit_price' => 9.00, 'qty' => 2]],
    ])->assertStatus(422);
});
