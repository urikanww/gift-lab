<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\StockMovement;
use App\Services\Procurement\ProcurementManager;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('advances a pending line to READY with no stock movement', function (): void {
    $product = Product::factory()->create();
    $quote = Quote::factory()->create(['state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'line_state' => LineItemState::Pending->value,
        'qty' => 12,
        'unit_price' => 4.50,
    ]);

    app(ProcurementManager::class)->markBought($line);

    expect($line->fresh()->line_state)->toBe(LineItemState::Ready)
        ->and((int) $line->fresh()->procured_qty)->toBe(12)
        ->and(StockMovement::query()->count())->toBe(0);
});

it('refuses to mark a line that is not pending or amended', function (): void {
    $product = Product::factory()->create();
    $quote = Quote::factory()->create(['state' => 'PROCURING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'line_state' => LineItemState::Ready->value,
    ]);

    app(ProcurementManager::class)->markBought($line);
})->throws(\App\Exceptions\DomainRuleException::class);
