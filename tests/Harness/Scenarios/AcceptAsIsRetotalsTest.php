<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\ProcurementManager;
use Tests\Harness\Agents\ProductionAgent;
use Tests\Harness\Agents\ValidatorAgent;
use Tests\Harness\Support\HarnessContext;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $this->ctx = new HarnessContext($this, $this->staff, $this->buyer);

    // Opt this tenant back into blocking on a shortfall so the line lands in
    // AWAITING_RECONFIRM (the path the accept-as-is decision resolves).
    PricingConfig::updateOrCreate(
        ['group' => 'procurement', 'key' => 'block_on_qty_short'],
        ['value' => 1],
    );
});

it('accept-as-is bills only what is produced (blocker 3 regression)', function (): void {
    $production = new ProductionAgent($this->ctx);
    $validator = new ValidatorAgent($this->ctx);

    // Stock 2, ordered 5 -> shortfall -> AWAITING_RECONFIRM.
    $variant = Variant::factory()->create(['product_id' => $this->product->id, 'stock_on_hand' => 2]);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $this->product->id,
        'variant_id' => $variant->id,
        'qty' => 5,
        'unit_price' => 15.00,
        'line_state' => 'PENDING',
    ]);
    $this->ctx->quote = $quote;

    app(ProcurementManager::class)->procureLine($line->load('product', 'variant'));
    expect($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');

    // Staff accept what could be sourced.
    $production->reconfirm($line->fresh(), 'approve');

    // Billed qty now equals produced qty; the invariant holds.
    $fresh = $line->fresh();
    expect((int) $fresh->qty)->toBe((int) $fresh->procured_qty);

    $validator->check('INVOICE_MATCHES_PRODUCED');
    expect($validator->violations())->toBeEmpty(
        implode("\n", array_map('strval', $validator->violations())),
    );
});
