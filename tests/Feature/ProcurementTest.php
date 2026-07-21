<?php

declare(strict_types=1);

use App\Events\LineItemAwaitingReconfirm;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\SupplierReorder;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\FixtureMarketplaceRechecker;
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

// Wave 3: a quantity shortfall is measured against stock figures nobody
// maintains, so it records a note and carries on rather than halting the order
// and paging staff to the procurement desk.
it('records a shortfall as advisory without blocking or paging staff', function (): void {
    Event::fake([LineItemAwaitingReconfirm::class]);
    $line = makeLine(stock: 2, qty: 5);

    $this->manager->procureLine($line->load('product', 'variant'));

    $fresh = $line->fresh();
    expect($fresh->line_state->value)->toBe('READY')
        ->and($fresh->procurement_note)->toContain('Only 2 of 5')
        // Proceeding at the ordered quantity: what is actually made is settled
        // by a person at the production gate, not by this figure.
        ->and($fresh->procured_qty)->toBe(5);
    Event::assertNotDispatched(LineItemAwaitingReconfirm::class);
});

// The escape hatch, so a tenant that does maintain its stock can have the old
// behaviour back without a deploy.
it('blocks on a shortfall again when block_on_qty_short is set', function (): void {
    Event::fake([LineItemAwaitingReconfirm::class]);
    PricingConfig::updateOrCreate(
        ['group' => 'procurement', 'key' => 'block_on_qty_short'],
        ['value' => 1],
    );
    $line = makeLine(stock: 2, qty: 5);

    $this->manager->procureLine($line->load('product', 'variant'));

    expect($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');
    Event::assertDispatched(LineItemAwaitingReconfirm::class);
});

// Price is a live marketplace read - real, current, and about money - so it
// still stops the order and asks a human.
it('still blocks and pages staff on a price jump', function (): void {
    Event::fake([LineItemAwaitingReconfirm::class]);
    $product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => 5,
        'unit_price' => 5.00,
        'line_state' => 'PENDING',
    ]);
    app(FixtureMarketplaceRechecker::class)->for($product->id, availableQty: 50, unitPrice: 9.00);

    $this->manager->procureLine($line->load('product'));

    expect($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');
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
    $po = Invoice::create([
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

// Wave 1: amend() rebuilt the subtotal from only the lines in the payload while
// validation required just min:1 of them, so a partial submission silently
// dropped the untouched lines from the money while leaving them on the order -
// ship five, charge for one. Amendments now merge over the full line set.
it('keeps untouched lines in the subtotal when only some lines are amended', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'DRAFT',
        'subtotal' => 50.00,
        'delivery' => 30.00,
        'total' => 80.00,
    ]);
    $amended = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => 2,
        'unit_price' => 15.00,
    ]);
    $untouched = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => 1,
        'unit_price' => 20.00,
    ]);

    // Only the first line is submitted: 2 x 16.00 = 32.00. The untouched line
    // (1 x 20.00) must still count toward the subtotal.
    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $amended->id, 'unit_price' => 16.00, 'qty' => 2]],
    ])->assertOk();

    $quote->refresh();
    expect((float) $quote->subtotal)->toBe(52.00)
        ->and((float) $quote->total)->toBe(82.00);

    // The omitted line is untouched, not silently zeroed or removed.
    $untouched->refresh();
    expect($untouched->qty)->toBe(1)
        ->and((float) $untouched->unit_price)->toBe(20.00);
});

// Wave 1: staff confirm stock at source and swap products a supplier no longer
// carries, so the amend screen has to add and remove lines, not just re-price
// them. Removal is explicit (removed_line_ids) rather than implied by omission -
// omission means "unchanged", per the merge behaviour above.

/** Two-line DRAFT quote: 2 x 15.00 + 1 x 20.00, delivery 30.00. */
function draftQuoteForAmend(Product $product): array
{
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'DRAFT',
        'subtotal' => 50.00,
        'delivery' => 30.00,
        'total' => 80.00,
    ]);

    $first = LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'variant_id' => null,
        'qty' => 2, 'unit_price' => 15.00,
    ]);
    $second = LineItem::factory()->create([
        'quote_id' => $quote->id, 'product_id' => $product->id, 'variant_id' => null,
        'qty' => 1, 'unit_price' => 20.00,
    ]);

    return [$quote, $first, $second];
}

it('adds a new line and counts it toward the subtotal', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    [$quote, $first, $second] = draftQuoteForAmend($product);
    $added = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [
            ['id' => $first->id, 'unit_price' => 15.00, 'qty' => 2],
            ['id' => $second->id, 'unit_price' => 20.00, 'qty' => 1],
            ['product_id' => $added->id, 'unit_price' => 12.00, 'qty' => 3],
        ],
    ])->assertOk();

    $quote->refresh();
    // 30.00 + 20.00 + 36.00 = 86.00, plus 30.00 delivery.
    expect($quote->lineItems()->count())->toBe(3)
        ->and((float) $quote->subtotal)->toBe(86.00)
        ->and((float) $quote->total)->toBe(116.00);

    $new = $quote->lineItems()->where('product_id', $added->id)->sole();
    expect($new->line_state->value)->toBe('PENDING')
        ->and($new->frozen_snapshot['product_name'])->toBe($added->name);
});

it('removes a line and drops it from the subtotal', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    [$quote, $first, $second] = draftQuoteForAmend($product);

    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $first->id, 'unit_price' => 15.00, 'qty' => 2]],
        'removed_line_ids' => [$second->id],
    ])->assertOk();

    $quote->refresh();
    expect($quote->lineItems()->count())->toBe(1)
        ->and((float) $quote->subtotal)->toBe(30.00)
        ->and((float) $quote->total)->toBe(60.00);

    // Soft-deleted, so the order's history survives the removal.
    $this->assertSoftDeleted('line_items', ['id' => $second->id]);
});

it('rejects an amendment that would remove every line', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    [$quote, $first, $second] = draftQuoteForAmend($product);

    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $first->id, 'unit_price' => 15.00, 'qty' => 2]],
        'removed_line_ids' => [$first->id, $second->id],
    ])->assertStatus(422)->assertJsonValidationErrors('removed_line_ids');

    expect($quote->fresh()->lineItems()->count())->toBe(2);
});

it('enforces the margin floor on an added line', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    [$quote, $first] = draftQuoteForAmend($product);
    $added = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);

    // Floor 12% over landed 10.00 => min 11.20; propose 9.00 => rejected.
    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [
            ['id' => $first->id, 'unit_price' => 15.00, 'qty' => 2],
            ['product_id' => $added->id, 'unit_price' => 9.00, 'qty' => 3],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('lines.1.unit_price');
});

it('amends delivery alone, with no lines in the payload', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    [$quote] = draftQuoteForAmend($product);

    // The goods fold and stack, so delivery drops. Nothing about the lines
    // changes - and the untouched lines must not be re-checked against the
    // margin floor just because delivery moved.
    $this->patchJson("/api/quotes/{$quote->id}/amend", ['delivery' => 12.00])->assertOk();

    $quote->refresh();
    expect((float) $quote->delivery)->toBe(12.00)
        ->and((float) $quote->subtotal)->toBe(50.00)
        ->and((float) $quote->total)->toBe(62.00);
});

it('refuses to remove a line belonging to another quote', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV']);
    [$quote, $first] = draftQuoteForAmend($product);

    $otherQuote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);
    $foreign = LineItem::factory()->create([
        'quote_id' => $otherQuote->id, 'product_id' => $product->id,
        'variant_id' => null, 'qty' => 1, 'unit_price' => 20.00,
    ]);

    $this->patchJson("/api/quotes/{$quote->id}/amend", [
        'lines' => [['id' => $first->id, 'unit_price' => 15.00, 'qty' => 2]],
        'removed_line_ids' => [$foreign->id],
    ])->assertStatus(422);

    expect($foreign->fresh())->not->toBeNull();
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

// Wave 3 / P0-3: "Accept as-is" moved the line to READY without re-totalling
// anything, so the client was invoiced for the quantity ordered while the floor
// only ever produced what could be sourced. The amend and drop branches both
// re-totalled; this one silently did not.
it('retotals the quote and invoice when a shortfall is accepted as-is', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 60]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'subtotal' => 1500.00,
        'delivery' => 50.00,
        'total' => 1550.00,
    ]);
    $invoice = Invoice::create([
        'quote_id' => $quote->id,
        'po_ref' => 'PO-ASIS',
        'payment_state' => 'UNPAID',
        'amount' => 1550.00,
        'currency' => 'SGD',
        'issued_at' => now(),
    ]);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    // Ordered 100, only 60 available.
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 100,
        'unit_price' => 15.00,
        'procured_qty' => 60,
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    $this->postJson("/api/line-items/{$line->id}/reconfirm", ['action' => 'approve'])->assertOk();

    // Bill follows the goods: 60 x 15.00 = 900.00, against an original line of
    // 1500.00. The line is what will actually be produced, too.
    $quote->refresh();
    expect($line->fresh()->qty)->toBe(60)
        ->and($line->fresh()->line_state->value)->toBe('READY')
        ->and((float) $quote->subtotal)->toBe(900.00)
        ->and((float) $quote->total)->toBe(950.00)
        ->and((float) $invoice->fresh()->amount)->toBe(950.00);
});

// procured_qty 0 means nothing could be sourced at all - no variant, no
// filament, no weight estimate. Accepting that builds a job for zero units and
// a line worth nothing; dropping the line is what staff actually mean.
it('refuses to accept a shortfall as-is when nothing could be sourced', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'class' => 'CORE', 'print_method' => 'UV']);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => 10,
        'unit_price' => 15.00,
        'procured_qty' => 0,
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    $this->postJson("/api/line-items/{$line->id}/reconfirm", ['action' => 'approve'])
        ->assertStatus(422);

    expect($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');
});

// A price jump accepted as-is is the other direction: the buyer keeps the
// quoted price and the margin is absorbed. Quantity is unchanged, so no money
// moves and the totals must stay exactly as they were.
it('leaves the totals alone when an accepted shortfall is only a price jump', function (): void {
    Sanctum::actingAs($this->staff);
    $product = Product::factory()->create(['base_cost' => 10, 'class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 100]);
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROCURING',
        'subtotal' => 150.00,
        'delivery' => 20.00,
        'total' => 170.00,
    ]);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 10,
        'unit_price' => 15.00,
        'procured_qty' => 10,
        'procured_price' => 19.00,
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    $this->postJson("/api/line-items/{$line->id}/reconfirm", ['action' => 'approve'])->assertOk();

    $quote->refresh();
    expect($line->fresh()->qty)->toBe(10)
        ->and((float) $quote->subtotal)->toBe(150.00)
        ->and((float) $quote->total)->toBe(170.00);
});

// Wave 3: the production gate. Jobs used to be built the moment the system
// believed every line was resolved - a belief resting on stock figures nobody
// maintains, since most goods are bought in after the order is placed. A person
// confirming the goods are in hand is now what releases the order to the floor.

/** A PROCURING quote whose single line has resolved to READY. */
function quoteAwaitingStockConfirmation(): Quote
{
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 500]);
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 5,
        'unit_price' => 15.00,
        'line_state' => 'READY',
    ]);

    return $quote;
}

it('holds a fully-procured order at PROCURING until stock is confirmed', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = quoteAwaitingStockConfirmation();

    // Running procurement resolves the lines but must not release the order.
    $this->postJson("/api/quotes/{$quote->id}/procure")->assertOk();

    $quote->refresh();
    expect($quote->state->value)->toBe('PROCURING')
        ->and($quote->jobs()->count())->toBe(0);
});

it('releases the order to the floor when staff confirm the stock', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = quoteAwaitingStockConfirmation();

    $this->postJson("/api/quotes/{$quote->id}/confirm-stock")->assertOk();

    $quote->refresh();
    expect($quote->state->value)->toBe('READY')
        ->and($quote->jobs()->count())->toBeGreaterThan(0)
        ->and($quote->stock_confirmed_by)->toBe($this->staff->id)
        ->and($quote->stock_confirmed_at)->not->toBeNull();
});

// The gate is the last safety net before production, so it records who looked.
it('writes an audit row naming who confirmed the stock', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = quoteAwaitingStockConfirmation();

    $this->postJson("/api/quotes/{$quote->id}/confirm-stock")->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'event' => 'quote.stock_confirmed',
        'auditable_id' => $quote->id,
        'user_id' => $this->staff->id,
    ]);
});

it('refuses to confirm stock while a line is still awaiting a decision', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = quoteAwaitingStockConfirmation();
    LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'variant_id' => null,
        'qty' => 3,
        'unit_price' => 15.00,
        'line_state' => 'AWAITING_RECONFIRM',
    ]);

    $this->postJson("/api/quotes/{$quote->id}/confirm-stock")->assertStatus(422);

    expect($quote->fresh()->state->value)->toBe('PROCURING');
});

it('refuses to confirm stock twice', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = quoteAwaitingStockConfirmation();

    $this->postJson("/api/quotes/{$quote->id}/confirm-stock")->assertOk();
    $this->postJson("/api/quotes/{$quote->id}/confirm-stock")->assertStatus(422);
});

it('refuses to let a buyer confirm stock', function (): void {
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $quote = quoteAwaitingStockConfirmation();
    Sanctum::actingAs($buyer);

    $this->postJson("/api/quotes/{$quote->id}/confirm-stock")->assertForbidden();

    expect($quote->fresh()->state->value)->toBe('PROCURING');
});
