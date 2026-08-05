<?php

declare(strict_types=1);

use App\Enums\LineItemState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'SCRAPED_UV', 'print_method' => 'UV']);
});

function buyListLine(): LineItem
{
    $quote = Quote::factory()->create([
        'company_id' => test()->company->id,
        'state' => 'PROOF_APPROVED',
        'accepted_at' => now(),
    ]);

    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => test()->product->id,
        'line_state' => LineItemState::Pending->value,
        'qty' => 2,
        'unit_price' => 3.00,
    ]);
}

it('lists buy-list lines and marks one bought', function (): void {
    Sanctum::actingAs($this->staff);
    $line = buyListLine();

    $this->getJson('/api/procurement/buy-list')
        ->assertOk()
        ->assertJsonFragment(['id' => $line->id]);

    $this->postJson("/api/line-items/{$line->id}/mark-bought")
        ->assertOk();

    expect($line->fresh()->line_state)->toBe(LineItemState::Ready);
});

it('marks every line for a product bought in one call', function (): void {
    Sanctum::actingAs($this->staff);
    $a = buyListLine();
    $b = buyListLine();

    $this->postJson("/api/procurement/buy-list/mark-product/{$this->product->id}")
        ->assertOk()
        ->assertJsonPath('marked', 2);

    expect($a->fresh()->line_state)->toBe(LineItemState::Ready)
        ->and($b->fresh()->line_state)->toBe(LineItemState::Ready);
});
