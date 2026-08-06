<?php

declare(strict_types=1);

use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Procurement\BuyListQuery;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('includes pending lines on proof-approved orders and excludes others', function (): void {
    $product = Product::factory()->create();

    $approved = Quote::factory()->create(['state' => 'PROOF_APPROVED']);
    $onBuyList = LineItem::factory()->create([
        'quote_id' => $approved->id,
        'product_id' => $product->id,
        'line_state' => 'PENDING',
    ]);

    // Excluded: artwork-only (price not agreed yet).
    $artworkOnly = Quote::factory()->create(['state' => 'ARTWORK_APPROVED']);
    LineItem::factory()->create([
        'quote_id' => $artworkOnly->id,
        'product_id' => $product->id,
        'line_state' => 'PENDING',
    ]);

    // Excluded: already bought (READY) on an eligible order.
    LineItem::factory()->create([
        'quote_id' => $approved->id,
        'product_id' => $product->id,
        'line_state' => 'READY',
    ]);

    $ids = app(BuyListQuery::class)->lines()->pluck('id')->all();

    expect($ids)->toBe([$onBuyList->id]);
});

it('scopes to a single product for the grouped bulk action', function (): void {
    $wanted = Product::factory()->create();
    $other = Product::factory()->create();
    $quote = Quote::factory()->create(['state' => 'CONFIRMED']);

    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $wanted->id,
        'line_state' => 'PENDING',
    ]);
    LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $other->id,
        'line_state' => 'PENDING',
    ]);

    $ids = app(BuyListQuery::class)->linesForProduct($wanted->id)->pluck('id')->all();

    expect($ids)->toBe([$line->id]);
});
