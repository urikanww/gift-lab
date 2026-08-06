<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\PricingConfig;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
});

function enablePayNow(): void
{
    PricingConfig::updateOrCreate(
        ['group' => 'config', 'key' => 'pay_now_cutoff'],
        ['value' => ['mode' => 'pay_now', 'b2c_enabled' => true]],
    );
}

function proofApprovedQuote(int $companyId): Quote
{
    $quote = Quote::factory()->create(['company_id' => $companyId, 'state' => 'PROOF_APPROVED', 'total' => 100]);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);
    $variant = Variant::factory()->create(['product_id' => $product->id, 'stock_on_hand' => 500]);
    LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'variant_id' => $variant->id,
        'qty' => 10,
        'line_state' => 'PENDING',
    ]);

    return $quote;
}

// Paying is the buy-list "bought" action for a B2C order: it bills the order and
// carries it straight to the production floor in one step, through the same gate
// as a manual buy (no separate staff stock-confirm). Previously payment stranded
// the order in PROCURING with no path forward once the confirm-stock UI was
// removed.
it('captures a B2C payment and sends the order to the floor, billed once', function (): void {
    enablePayNow();
    $quote = proofApprovedQuote($this->company->id);

    Sanctum::actingAs($this->buyer);
    $response = $this->postJson("/api/quotes/{$quote->id}/pay")->assertOk();

    expect($response->json('paid'))->toBeTrue()
        ->and($quote->fresh()->state->value)->toBe('READY')
        ->and($quote->fresh()->stock_confirmed_at)->not->toBeNull();

    // Billed exactly once, and marked paid.
    expect(\App\Models\Invoice::query()->where('quote_id', $quote->id)->count())->toBe(1);
    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'PAID',
    ]);
});

it('refuses pay-now when B2C is disabled', function (): void {
    // pay_now_cutoff seeded with b2c_enabled=false by default.
    $quote = proofApprovedQuote($this->company->id);

    Sanctum::actingAs($this->buyer);
    // Feature-gated: FeatureNotEnabledException maps to a friendly 409 (not 500).
    $this->postJson("/api/quotes/{$quote->id}/pay")->assertStatus(409);
});

it('forbids paying for another company quote', function (): void {
    enablePayNow();
    $other = Company::factory()->create();
    $quote = proofApprovedQuote($other->id);

    Sanctum::actingAs($this->buyer);
    $this->postJson("/api/quotes/{$quote->id}/pay")->assertForbidden();
});
