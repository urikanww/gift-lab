<?php

declare(strict_types=1);

use App\Events\QuoteStateChanged;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
    $this->product = Product::factory()->create(['base_cost' => 10, 'print_method' => 'UV', 'publish_state' => 'PUBLISHED']);
});

it('lets a buyer create a draft quote priced from config', function (): void {
    Sanctum::actingAs($this->buyer);

    $response = $this->postJson('/api/quotes', [
        'company_id' => $this->company->id,
        'line_items' => [
            ['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 3],
        ],
    ]);

    $response->assertCreated()->assertJsonPath('data.state', 'DRAFT');
    expect((float) $response->json('data.total'))->toBeGreaterThan(0.0);
    $this->assertDatabaseCount('line_items', 1);
});

it('forbids a buyer creating a quote for another company', function (): void {
    Sanctum::actingAs($this->buyer);
    $other = Company::factory()->create();

    $this->postJson('/api/quotes', [
        'company_id' => $other->id,
        'line_items' => [['product_id' => $this->product->id, 'variant_id' => null, 'qty' => 1]],
    ])->assertStatus(422);
});

it('broadcasts a state change when a quote is sent', function (): void {
    Event::fake([QuoteStateChanged::class]);
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);

    $this->postJson("/api/quotes/{$quote->id}/send")->assertOk();

    expect($quote->fresh()->state->value)->toBe('SENT');
    Event::assertDispatched(QuoteStateChanged::class);
});

it('blocks an illegal transition with a server error, leaving state intact', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'READY']);

    // READY cannot be "sent"; the guarded transition throws.
    $this->postJson("/api/quotes/{$quote->id}/send")->assertStatus(500);
    expect($quote->fresh()->state->value)->toBe('READY');
});
