<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;
use App\Services\QueueService;

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

it('projects SHIPPED then DELIVERED on /track as the jobs advance', function (): void {
    $company = Company::factory()->create(['billing_email' => 'buyer@acme.com']);
    $product = Product::factory()->create(['class' => 'CORE', 'print_method' => 'UV']);

    // Drive a quote onto the floor: PROCURING + approved proof + a ready line,
    // then build the production job (moves the quote to READY).
    $quote = Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROCURING']);
    Proof::factory()->approved()->create(['quote_id' => $quote->id]);
    LineItem::factory()->ready()->create([
        'quote_id' => $quote->id,
        'product_id' => $product->id,
        'qty' => 10,
    ]);

    $queue = app(QueueService::class);
    $job = $queue->buildJobsForQuote($quote->load('lineItems.product'))->first();

    $track = fn (): array => $this->postJson('/api/track', [
        'tracking_code' => $quote->tracking_code,
        'email' => 'buyer@acme.com',
    ])->assertOk()->json();

    // On the floor, not yet shipped.
    expect($track()['stage'])->toBe('IN_PRODUCTION');

    // Ship the (only) job → the public tracker must report SHIPPED.
    $queue->advance($job, JobState::InProduction);
    $queue->advance($job, JobState::Shipped, 'SP123456789SG');
    expect($track()['stage'])->toBe('SHIPPED');

    // Close the job → the quote closes (READY -> CLOSED) → tracker DELIVERED.
    $queue->advance($job->fresh(), JobState::Closed);
    expect($quote->fresh()->state->value)->toBe('CLOSED');
    expect($track()['stage'])->toBe('DELIVERED');
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
