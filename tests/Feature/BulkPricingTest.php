<?php

declare(strict_types=1);

use App\Models\PricingConfig;

beforeEach(function (): void {
    // The resolved-value memo is static for the whole PHP process, so a value
    // read in one test would otherwise leak into the next.
    PricingConfig::flushMemo();
});

it('exposes the bulk threshold without auth', function (): void {
    seedPricing();

    $this->getJson('/api/bulk-pricing')
        ->assertOk()
        ->assertJson(['bulk_qty' => 50, 'bulk_discount_pct' => 10]);
});

it('reflects an edited threshold config', function (): void {
    seedPricing();
    PricingConfig::query()->where('group', 'threshold')->where('key', 'bulk_qty')->first()
        ->update(['value' => 250]);
    PricingConfig::query()->where('group', 'threshold')->where('key', 'bulk_discount_pct')->first()
        ->update(['value' => 7.5]);

    $this->getJson('/api/bulk-pricing')
        ->assertOk()
        ->assertJson(['bulk_qty' => 250, 'bulk_discount_pct' => 7.5]);
});

it('reports no bulk offer as null rather than PHP_INT_MAX when the row is absent', function (): void {
    // No pricing config seeded at all: bulk_qty defaults to PHP_INT_MAX
    // internally, which must never reach a client.
    $response = $this->getJson('/api/bulk-pricing')->assertOk();

    expect($response->json('bulk_qty'))->toBeNull()
        ->and($response->json('bulk_discount_pct'))->toEqual(0);
});

it('reports no bulk offer when the discount is zero', function (): void {
    seedPricing();
    PricingConfig::query()->where('group', 'threshold')->where('key', 'bulk_discount_pct')->first()
        ->update(['value' => 0]);

    $response = $this->getJson('/api/bulk-pricing')->assertOk();

    expect($response->json('bulk_qty'))->toBeNull()
        ->and($response->json('bulk_discount_pct'))->toEqual(0);
});

it('leaks nothing beyond the two customer-facing threshold keys', function (): void {
    seedPricing();

    $body = $this->getJson('/api/bulk-pricing')->assertOk()->json();

    // Landed cost, margin and fee inputs are business intel - the storefront
    // must never see them, however the endpoint is later extended.
    expect(array_keys($body))->toEqualCanonicalizing(['bulk_qty', 'bulk_discount_pct']);

    $raw = json_encode($body);
    foreach (['margin', 'landed', 'print_cost', 'fee', 'default_pct', 'setup_fee'] as $forbidden) {
        expect($raw)->not->toContain($forbidden);
    }
});
