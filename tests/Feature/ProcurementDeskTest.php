<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * P0-2: the procurement desk had no data source. It subscribed to a broadcast
 * and nothing else, so a blocked line was visible only to whoever had the page
 * open at the instant it broke — including, absurdly, the staff who followed the
 * "Go to procurement desk" link placed on the order because a line was blocked.
 */
beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
});

function blockedLine(string $state = 'AWAITING_RECONFIRM'): LineItem
{
    $quote = Quote::factory()->create(['company_id' => test()->company->id, 'state' => 'PROCURING']);

    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => test()->product->id,
        'variant_id' => null,
        'qty' => 10,
        'unit_price' => 15.00,
        'procured_qty' => 4,
        'line_state' => $state,
    ]);
}

it('lists every line awaiting a decision, for staff who were not watching', function (): void {
    $blocked = blockedLine();
    Sanctum::actingAs($this->staff);

    $this->getJson('/api/procurement/awaiting-reconfirm')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $blocked->id);
});

it('excludes lines that are not awaiting a decision', function (): void {
    blockedLine('READY');
    blockedLine('DROPPED');
    Sanctum::actingAs($this->staff);

    $this->getJson('/api/procurement/awaiting-reconfirm')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// A line blocking an order for two days matters more than one that broke a
// minute ago, so the desk works oldest-first.
it('orders the desk oldest first', function (): void {
    $older = blockedLine();
    $older->forceFill(['updated_at' => now()->subDays(2)])->saveQuietly();
    $newer = blockedLine();
    $newer->forceFill(['updated_at' => now()])->saveQuietly();

    Sanctum::actingAs($this->staff);

    $this->getJson('/api/procurement/awaiting-reconfirm')
        ->assertOk()
        ->assertJsonPath('data.0.id', $older->id)
        ->assertJsonPath('data.1.id', $newer->id);
});

it('refuses the desk to a buyer', function (): void {
    blockedLine();
    $buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    Sanctum::actingAs($buyer);

    $this->getJson('/api/procurement/awaiting-reconfirm')->assertForbidden();
});

it('refuses the desk to a guest', function (): void {
    $this->getJson('/api/procurement/awaiting-reconfirm')->assertUnauthorized();
});
