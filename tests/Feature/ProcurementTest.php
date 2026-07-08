<?php

declare(strict_types=1);

use App\Events\LineItemAwaitingReconfirm;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SupplierReorder;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\ProcurementManager;
use App\Services\QuoteService;
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

// D12 (Pass 2 F4): reconfirmation amend enforces the same margin floor as the
// pre-send amend - the re-quote path is exactly where underpricing happened.
it('rejects a reconfirmation amend below the margin floor', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 30, 'class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 450]);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 10000,
        'unit_price' => 42.00,
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    // Floor 12% over landed 30.00 => min 33.60; 0.01 must be rejected.
    $this->postJson("/api/line-items/{$line->id}/reconfirm", [
        'action' => 'amend',
        'qty' => 400,
        'unit_price' => 0.01,
    ])->assertStatus(422)->assertJsonValidationErrors('unit_price');

    expect($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');
});

// A11 (Pass 2 F5): a reconfirmation amend/drop re-anchors the quote totals and
// the issued PO amount so the invoice matches what is actually fulfilled.
it('retotals the quote and PO after a reconfirmation amend', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 30, 'class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 450]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'subtotal' => 422533.00,
        'delivery' => 60.00,
        'total' => 422593.00,
    ]);
    $po = PurchaseOrder::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-TEST',
        'payment_state' => 'UNPAID',
        'amount' => 422593.00,
        'currency' => 'SGD',
        'issued_at' => now(),
    ]);
    // The amended line goes READY and tryQueue fires; the production gate
    // requires an approved proof (as it did in the live repro).
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 10000,
        'unit_price' => 42.25,
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    $this->postJson("/api/line-items/{$line->id}/reconfirm", [
        'action' => 'amend',
        'qty' => 400,
        'unit_price' => 40.00,
    ])->assertOk();

    // Line total went 422500.00 -> 16000.00 (delta -406500.00); the SGD 33 of
    // setup/customization fees baked into the original subtotal must survive.
    $quote->refresh();
    expect((float) $quote->subtotal)->toBe(16033.00)
        ->and((float) $quote->total)->toBe(16093.00)
        ->and((float) $po->fresh()->amount)->toBe(16093.00);
});

it('retotals the quote and PO after a reconfirmation drop', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 30, 'class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 0]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'subtotal' => 500.00,
        'delivery' => 30.00,
        'total' => 530.00,
    ]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 10,
        'unit_price' => 40.00,
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    $this->postJson("/api/line-items/{$line->id}/reconfirm", ['action' => 'drop'])->assertOk();

    $quote->refresh();
    expect((float) $quote->subtotal)->toBe(100.00)
        ->and((float) $quote->total)->toBe(130.00);
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

// Stock ledger integration: procurement consumes through the append-only ledger,
// backorder lets on-demand products sell at 0, and cancellation returns stock.

it('records a SALE movement through the ledger when a CORE line procures', function (): void {
    $line = makeLine(stock: 100, qty: 5);

    $this->manager->procureLine($line->load('product', 'variant'));

    $this->assertDatabaseHas('stock_movements', [
        'variant_id' => $line->variant->id,
        'delta' => -5,
        'reason' => 'SALE',
    ]);
    expect($line->variant->fresh()->stock_on_hand)->toBe(95);
});

it('fulfils a backordered CORE line at insufficient stock, driving on-hand negative', function (): void {
    test()->product->update(['allow_backorder' => true]);
    $line = makeLine(stock: 2, qty: 5);

    $this->manager->procureLine($line->load('product', 'variant'));

    // Not blocked, not sent to reconfirm: full qty sold, balance goes negative
    // (the -3 is the procurement worklist).
    expect($line->fresh()->line_state->value)->toBe('READY')
        ->and($line->variant->fresh()->stock_on_hand)->toBe(-3);
    $this->assertDatabaseHas('stock_movements', [
        'variant_id' => $line->variant->id,
        'delta' => -5,
        'reason' => 'SALE',
    ]);
});

it('drafts a reorder covering the backorder deficit, never a zero qty', function (): void {
    test()->product->update(['allow_backorder' => true]);
    // Zero threshold is the case that used to draft qty 0 (threshold * 2).
    $variant = Variant::factory()->create([
        'product_id' => $this->product->id,
        'stock_on_hand' => 0,
        'reorder_threshold' => 0,
    ]);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'variant_id' => $variant->id,
        'qty' => 5,
        'unit_price' => 15.00,
        'line_state' => 'PENDING',
    ]);

    $this->manager->procureLine($line->load('product', 'variant'));

    // buffer max(0*2, 1) = 1 + deficit 5 = 6, not 0.
    $reorder = SupplierReorder::where('variant_id', $variant->id)->firstOrFail();
    expect($variant->fresh()->stock_on_hand)->toBe(-5)
        ->and((int) round((float) $reorder->qty))->toBe(6);
});

it('returns consumed stock as a RETURN movement when a quote is cancelled', function (): void {
    $line = makeLine(stock: 100, qty: 5);
    $this->manager->procureLine($line->load('product', 'variant'));
    expect($line->variant->fresh()->stock_on_hand)->toBe(95);

    app(QuoteService::class)->cancel($line->quote, 'changed mind');

    expect($line->variant->fresh()->stock_on_hand)->toBe(100);
    $this->assertDatabaseHas('stock_movements', [
        'variant_id' => $line->variant->id,
        'delta' => 5,
        'reason' => 'RETURN',
    ]);
});
