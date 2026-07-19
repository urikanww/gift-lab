<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Catalogue\CompletenessGate;

function scrapedProduct(array $overrides = []): Product
{
    return new Product(array_merge([
        'class' => 'SCRAPED_UV',
        'name' => 'Test',
        'base_cost' => 5.00,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10, 'unit' => 'mm'],
        'weight' => 100,
        'is_printable' => true,
        'print_method' => 'UV',
        'stock_estimate' => 20,
    ], $overrides));
}

it('passes a fully complete scraped product', function (): void {
    expect((new CompletenessGate())->isComplete(scrapedProduct()))->toBeTrue();
});

it('flags missing price', function (): void {
    expect((new CompletenessGate())->reasons(scrapedProduct(['base_cost' => 0])))
        ->toContain('missing_price');
});

it('flags missing dimensions/weight', function (): void {
    expect((new CompletenessGate())->reasons(scrapedProduct(['weight' => null])))
        ->toContain('missing_dimensions');
});

it('flags not printable', function (): void {
    expect((new CompletenessGate())->reasons(scrapedProduct(['is_printable' => false])))
        ->toContain('not_printable');
});

it('flags unreadable stock for a STOCKED item we actually hold', function (): void {
    expect((new CompletenessGate())->reasons(
        scrapedProduct(['stock_mode' => 'STOCKED', 'stock_estimate' => null]),
    ))->toContain('stock_unreadable');
});

it('waives stock for a buy-per-order (MAKE_TO_ORDER) blank', function (): void {
    // Stock is unknowable for third-party affiliate listings and checked by a
    // human at procurement, so a null estimate must NOT block publication.
    expect((new CompletenessGate())->reasons(
        scrapedProduct(['stock_mode' => 'MAKE_TO_ORDER', 'stock_estimate' => null]),
    ))->not->toContain('stock_unreadable');
});
