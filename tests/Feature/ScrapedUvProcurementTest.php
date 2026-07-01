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

it('flags QTY_SHORT when the marketplace lacks stock', function (): void {
    $line = scrapedLine(qty: 10, quotedUnit: 5.00);
    $this->rechecker->for($line->product_id, availableQty: 3, unitPrice: 5.00);

    $result = $this->manager->procureLine($line->load('product'));

    expect($result->outcome->value)->toBe('QTY_SHORT')
        ->and($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM')
        ->and($line->fresh()->procured_qty)->toBe(3);
});

it('flags PRICE_JUMPED when re-check price exceeds tolerance', function (): void {
    $line = scrapedLine(qty: 10, quotedUnit: 5.00);
    // >10% jump over quoted 5.00.
    $this->rechecker->for($line->product_id, availableQty: 20, unitPrice: 6.50);

    $result = $this->manager->procureLine($line->load('product'));

    expect($result->outcome->value)->toBe('PRICE_JUMPED')
        ->and($line->fresh()->line_state->value)->toBe('AWAITING_RECONFIRM');
});
