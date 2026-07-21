<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Procurement\FixtureMarketplaceRechecker;
use App\Services\Procurement\ProcurementManager;

beforeEach(function (): void {
    seedPricing();
    $this->rechecker = app(FixtureMarketplaceRechecker::class);
    $this->manager = app(ProcurementManager::class);
    $this->company = Company::factory()->create();
});

function scrapedLine(int $qty, float $quotedUnit): LineItem
{
    $product = Product::factory()->scrapedUv()->create(['stock_estimate' => 0]);
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);

    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => $qty,
        'unit_price' => $quotedUnit,
        'line_state' => 'PENDING',
    ]);
}

it('procures a scraped line when re-check confirms qty and price', function (): void {
    $line = scrapedLine(qty: 10, quotedUnit: 5.00);
    $this->rechecker->for($line->product_id, availableQty: 20, unitPrice: 5.00);

    $result = $this->manager->procureLine($line->load('product'));

    expect($result->outcome->value)->toBe('OK')
        ->and($line->fresh()->line_state->value)->toBe('READY');
});

// Quantity is advisory; the order carries on and staff check it at the
// production gate. Contrast the price test below, which still blocks.
it('reports QTY_SHORT from the marketplace but does not block the order', function (): void {
    $line = scrapedLine(qty: 10, quotedUnit: 5.00);
    $this->rechecker->for($line->product_id, availableQty: 3, unitPrice: 5.00);

    $result = $this->manager->procureLine($line->load('product'));

    expect($result->outcome->value)->toBe('QTY_SHORT');

    $fresh = $line->fresh();
    expect($fresh->line_state->value)->toBe('READY')
        ->and($fresh->procurement_note)->toContain('3 of 10');
});

it('flags PRICE_JUMPED when re-check price exceeds tolerance', function (): void {
    $line = scrapedLine(qty: 10, quotedUnit: 5.00);
    // >10% jump over quoted 5.00.
    $this->rechecker->for($line->product_id, availableQty: 20, unitPrice: 6.50);

    $result = $this->manager->procureLine($line->load('product'));

    expect($result->outcome->value)->toBe('PRICE_JUMPED')
        ->and($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');
});
