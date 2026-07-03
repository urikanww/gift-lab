<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;

it('assigns an opaque tracking code to every new quote', function (): void {
    $quote = Quote::factory()->create();

    expect($quote->tracking_code)->toStartWith('GL-')
        ->and(strlen((string) $quote->tracking_code))->toBe(9);
});

it('returns order status for a matching code and email prefix', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);

    $this->postJson('/api/track', [
        'tracking_code' => $quote->tracking_code,
        'email' => 'buyer@acme.com',
    ])
        ->assertOk()
        ->assertJson([
            'reference' => $quote->tracking_code,
            'stage' => 'REVIEW',
            'stage_label' => 'In review',
        ])
        // Status only — no pricing or line detail may leak on the public page.
        ->assertJsonMissing(['total'])
        ->assertJsonMissing(['subtotal']);
});

it('accepts a lookup using only the first five email characters', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOF_APPROVED']);

    $this->postJson('/api/track', [
        'tracking_code' => strtolower((string) $quote->tracking_code), // case-insensitive
        'email' => 'buyer',
    ])
        ->assertOk()
        ->assertJson(['stage' => 'CONFIRMED']);
});

it('gives the same generic 404 for a wrong email prefix and an unknown code', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);

    $wrongEmail = $this->postJson('/api/track', [
        'tracking_code' => $quote->tracking_code,
        'email' => 'nope@other.com',
    ])->assertNotFound();

    $unknownCode = $this->postJson('/api/track', [
        'tracking_code' => 'GL-000000',
        'email' => 'buyer@acme.com',
    ])->assertNotFound();

    // Identical body — a caller cannot tell which field was wrong.
    expect($wrongEmail->json('message'))->toBe($unknownCode->json('message'))
        ->and($wrongEmail->json('message'))->toBe('No order matches those details.');
});
