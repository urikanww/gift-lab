<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\ShippingAddress;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('has a one-to-one shipping address relation', function (): void {
    $quote = Quote::factory()->create();
    $addr = ShippingAddress::create([
        'quote_id' => $quote->id,
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'city' => 'Singapore',
        'postal_code' => '018989',
        'country' => 'SG',
    ]);

    expect($quote->fresh()->shippingAddress->id)->toBe($addr->id);
});

it('falls back to the company address when none is set', function (): void {
    $quote = Quote::factory()->create();
    $quote->company->update(['address' => '10 Anson Rd, Singapore 079903']);

    $default = $quote->shippingAddressOrDefault();
    expect($default['line1'])->toContain('Anson')
        ->and($default['recipient_name'])->toBe($quote->company->name)
        ->and($default['country'])->toBe('SG');
});

it('round-trips a saved shipping address through the default helper', function (): void {
    $quote = Quote::factory()->create();
    ShippingAddress::create([
        'quote_id' => $quote->id,
        'recipient_name' => 'Rachel Tan',
        'phone' => '+6591234567',
        'line1' => '1 Marina Blvd',
        'city' => 'Singapore',
        'postal_code' => '018989',
        'country' => 'SG',
    ]);

    $addr = $quote->fresh()->shippingAddressOrDefault();
    expect($addr['recipient_name'])->toBe('Rachel Tan')
        ->and($addr['line1'])->toBe('1 Marina Blvd')
        ->and($addr['postal_code'])->toBe('018989');
});

it('lets staff read a quote shipping address (defaulted)', function (): void {
    $quote = Quote::factory()->create();
    $quote->company->update(['address' => '10 Anson Rd']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->getJson("/api/quotes/{$quote->id}/shipping-address")
        ->assertOk()->assertJsonPath('data.line1', '10 Anson Rd');
});

it('lets staff upsert a quote shipping address', function (): void {
    $quote = Quote::factory()->create();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->putJson("/api/quotes/{$quote->id}/shipping-address", [
        'recipient_name' => 'Rachel Tan', 'phone' => '+6591234567',
        'line1' => '1 Marina Blvd', 'postal_code' => '018989', 'country' => 'SG',
    ])->assertOk();

    expect($quote->fresh()->shippingAddress->recipient_name)->toBe('Rachel Tan');
});

it('forbids a buyer from editing the shipping address', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id]);
    Sanctum::actingAs($buyer);

    $this->putJson("/api/quotes/{$quote->id}/shipping-address", [
        'recipient_name' => 'X', 'phone' => '1', 'line1' => 'Y', 'postal_code' => '1',
    ])->assertStatus(403);
});

it('forbids a buyer from reading the shipping address', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id]);
    Sanctum::actingAs($buyer);

    $this->getJson("/api/quotes/{$quote->id}/shipping-address")->assertStatus(403);
});
