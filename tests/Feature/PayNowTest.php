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

// Payment carries the order as far as procurement, and no further. Production
// now waits for a person to confirm the goods are in hand - paying does not
// bypass that gate any more than staff issuing an invoice does.
it('captures a B2C payment and drives the quote to procurement', function (): void {
    enablePayNow();
    $quote = proofApprovedQuote($this->company->id);

    Sanctum::actingAs($this->buyer);
    $response = $this->postJson("/api/quotes/{$quote->id}/pay")->assertOk();

    expect($response->json('paid'))->toBeTrue()
        ->and($quote->fresh()->state->value)->toBe('PROCURING')
        ->and($quote->fresh()->stock_confirmed_at)->toBeNull();

    $this->assertDatabaseHas('invoices', [
        'quote_id' => $quote->id,
        'payment_state' => 'PAID',
    ]);
});

it('releases a paid order to the floor once staff confirm the stock', function (): void {
    enablePayNow();
    $quote = proofApprovedQuote($this->company->id);

    Sanctum::actingAs($this->buyer);
    $this->postJson("/api/quotes/{$quote->id}/pay")->assertOk();

    $staff = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/confirm-stock")->assertOk();

    $quote->refresh();
    expect($quote->state->value)->toBe('READY')
        ->and($quote->stock_confirmed_by)->toBe($staff->id)
        ->and($quote->stock_confirmed_at)->not->toBeNull();
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
