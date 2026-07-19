<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Product;
use App\Models\SavedAddress;
use App\Models\User;
use App\Models\Variant;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    seedPricing();
});

function checkoutLineItems(): array
{
    $product = Product::factory()->create(['publish_state' => 'PUBLISHED']);
    // CORE products need at least one variant to be quotable (E4 guard).
    Variant::factory()->create(['product_id' => $product->id]);

    return [['product_id' => $product->id, 'variant_id' => null, 'qty' => 1]];
}

function shippingPayload(array $overrides = []): array
{
    return array_merge([
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'postal_code' => '018989',
    ], $overrides);
}

it('rejects a buyer checkout with no shipping address', function (): void {
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
    ])->assertStatus(422)->assertJsonValidationErrors('shipping_address');
});

it('snapshots the shipping address onto the quote', function (): void {
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $response = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
        'shipping_address' => shippingPayload(['recipient_name' => 'Site B Reception']),
    ])->assertCreated();

    $quoteId = $response->json('data.id');
    $addr = \App\Models\Quote::find($quoteId)->shippingAddress;
    expect($addr->recipient_name)->toBe('Site B Reception')
        ->and($addr->postal_code)->toBe('018989')
        ->and($addr->country)->toBe('SG');
});

it('keeps the order address unchanged when a saved address is later edited', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    Sanctum::actingAs($user);

    $saved = SavedAddress::factory()->create(['user_id' => $user->id, 'recipient_name' => 'Original']);
    $response = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
        'shipping_address' => shippingPayload(['recipient_name' => 'Original']),
    ])->assertCreated();
    $quoteId = $response->json('data.id');

    $saved->update(['recipient_name' => 'Renamed']);

    expect(\App\Models\Quote::find($quoteId)->shippingAddress->recipient_name)->toBe('Original');
});

it('lets staff create a quote without a shipping address (company default)', function (): void {
    $company = Company::factory()->create(['address' => '10 Anson Rd']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $response = $this->postJson('/api/quotes', [
        'company_id' => $company->id,
        'line_items' => checkoutLineItems(),
    ])->assertCreated();

    $quote = \App\Models\Quote::find($response->json('data.id'));
    expect($quote->shippingAddress)->toBeNull()
        ->and($quote->shippingAddressOrDefault()['line1'])->toBe('10 Anson Rd');
});
