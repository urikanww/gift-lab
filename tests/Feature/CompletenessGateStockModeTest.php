<?php

declare(strict_types=1);

use App\Enums\StockMode;
use App\Models\Product;
use App\Services\Catalogue\CompletenessGate;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('never returns stock_unreadable, even for a stocked product with no estimate', function (): void {
    $product = Product::factory()->create([
        'stock_mode' => StockMode::Stocked->value,
        'stock_estimate' => null,
        'base_cost' => 5.00,
        'weight' => 0.2,
        'dimensions' => ['l' => 10, 'w' => 10, 'h' => 10],
        'is_printable' => true,
        'print_method' => 'UV',
    ]);

    $reasons = app(CompletenessGate::class)->reasons($product);

    expect($reasons)->not->toContain('stock_unreadable');
});
