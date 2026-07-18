<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\ShippingAddress;

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
