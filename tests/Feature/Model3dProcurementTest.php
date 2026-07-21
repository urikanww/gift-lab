<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Filament;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Procurement\ProcurementManager;

beforeEach(function (): void {
    seedPricing();
    $this->manager = app(ProcurementManager::class);
    $this->company = Company::factory()->create();
});

function model3dLine(int $qty, float $estGrams, float $filamentGrams, float $threshold = 100): LineItem
{
    Filament::create([
        'material' => 'PLA',
        'color' => 'Black',
        'qty_on_hand' => $filamentGrams,
        'reorder_threshold' => $threshold,
    ]);

    $product = Product::factory()->model3d()->create([
        'filament_material' => 'PLA',
        'filament_color' => 'Black',
        'est_grams' => $estGrams,
    ]);
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);

    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => null,
        'qty' => $qty,
        'unit_price' => 12.00,
        'line_state' => 'PENDING',
    ]);
}

it('prints a 3D line by consuming filament', function (): void {
    $line = model3dLine(qty: 5, estGrams: 40, filamentGrams: 1000, threshold: 100);

    $result = $this->manager->procureLine($line->load('product'));

    expect($result->outcome->value)->toBe('OK')
        ->and($line->fresh()->line_state->value)->toBe('READY');
    // 1000 - (40 * 5) = 800 remaining.
    expect((float) Filament::first()->qty_on_hand)->toBe(800.0);
});

// The strategy still reports the shortfall honestly - it is the manager that
// decides a quantity finding no longer blocks. Filament is not tracked at all
// here, so blocking on it would hold up orders over a number nobody maintains.
it('reports QTY_SHORT for filament but does not block the order', function (): void {
    $line = model3dLine(qty: 10, estGrams: 40, filamentGrams: 100); // covers only 2

    $result = $this->manager->procureLine($line->load('product'));

    expect($result->outcome->value)->toBe('QTY_SHORT')
        ->and($result->procuredQty)->toBe(2);

    $fresh = $line->fresh();
    expect($fresh->line_state->value)->toBe('READY')
        ->and($fresh->procurement_note)->toContain('covers 2 of 10');
});

it('drafts a filament reorder when the spool drops below threshold', function (): void {
    // 500 on hand, threshold 480, consume 40*5=200 -> 300 <= 480 -> reorder.
    $line = model3dLine(qty: 5, estGrams: 40, filamentGrams: 500, threshold: 480);

    $this->manager->procureLine($line->load('product'));

    $this->assertDatabaseHas('supplier_reorders', ['filament_id' => Filament::first()->id, 'state' => 'DRAFT']);
});
