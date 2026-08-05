<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Enums\QuoteState;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Services\QuoteService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
});

function buyListQuote(): Quote
{
    return Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'PROOF_APPROVED',
        'accepted_at' => now(), // price agreed — issueInvoice precondition
    ]);
}

it('raises the bill once and moves the order to the floor when the last line is bought', function (): void {
    $product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
    $quote = buyListQuote();
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'line_state' => LineItemState::Pending->value,
        'qty' => 3,
        'unit_price' => 6.00,
    ]);

    app(QuoteService::class)->markLineBought($line->fresh());

    expect($quote->fresh()->state)->toBe(QuoteState::Ready)
        ->and(Invoice::query()->where('quote_id', $quote->id)->count())->toBe(1)
        ->and($line->fresh()->line_state)->toBe(LineItemState::Ready);
});

it('does not double-bill when a second line is bought and is a no-op on re-click', function (): void {
    $product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
    $quote = buyListQuote();
    $a = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'line_state' => LineItemState::Pending->value, 'qty' => 1, 'unit_price' => 2]);
    $b = LineItem::factory()->create(['quote_id' => $quote->id, 'product_id' => $product->id, 'line_state' => LineItemState::Pending->value, 'qty' => 1, 'unit_price' => 2]);

    $svc = app(QuoteService::class);
    $svc->markLineBought($a->fresh());
    expect($quote->fresh()->state)->toBe(QuoteState::Procuring); // one line still open

    $svc->markLineBought($b->fresh());
    $svc->markLineBought($b->fresh()); // re-click: no-op

    expect(Invoice::query()->where('quote_id', $quote->id)->count())->toBe(1)
        ->and($quote->fresh()->state)->toBe(QuoteState::Ready);
});

it('marks every eligible line for one product across orders', function (): void {
    $product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
    $q1 = buyListQuote();
    $q2 = buyListQuote();
    LineItem::factory()->create(['quote_id' => $q1->id, 'product_id' => $product->id, 'line_state' => LineItemState::Pending->value, 'qty' => 1, 'unit_price' => 2]);
    LineItem::factory()->create(['quote_id' => $q2->id, 'product_id' => $product->id, 'line_state' => LineItemState::Pending->value, 'qty' => 1, 'unit_price' => 2]);

    $count = app(QuoteService::class)->markProductBought($product->id);

    expect($count)->toBe(2)
        ->and($q1->fresh()->state)->toBe(QuoteState::Ready)
        ->and($q2->fresh()->state)->toBe(QuoteState::Ready);
});
