<?php

declare(strict_types=1);

use App\Enums\StockMode;
use App\Models\Product;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('defaults a new product to MAKE_TO_ORDER when stock_mode is not given', function (): void {
    $product = new Product();

    expect($product->stock_mode)->toBe(StockMode::MakeToOrder);
});
