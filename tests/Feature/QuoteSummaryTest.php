<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\PricingConfig;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('buckets a buyer’s orders by lifecycle stage, scoped to their company', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);

    // Own company: 1 awaiting (SENT), 1 awaiting (PROOFING), 1 in production
    // (CONFIRMED), 1 completed (CLOSED), 1 cancelled (excluded from active).
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'SENT']);
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOFING']);
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'CONFIRMED']);
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'CLOSED']);
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'CANCELLED']);
    // Another company's order must not leak into the buyer's counts.
    Quote::factory()->create(['company_id' => Company::factory()->create()->id, 'state' => 'SENT']);

    Sanctum::actingAs($buyer);

    $this->getJson('/api/quotes/summary')
        ->assertOk()
        ->assertJson([
            'active' => 3, // SENT + PROOFING + CONFIRMED (not CLOSED/CANCELLED)
            'awaiting' => 2, // SENT + PROOFING
            'in_production' => 1, // CONFIRMED
            'completed' => 1, // CLOSED
            'total' => 5,
        ])
        ->assertJsonCount(2, 'awaiting_orders');
});

it('returns zeros for a buyer with no orders', function (): void {
    $company = Company::factory()->create();
    Sanctum::actingAs(User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']));

    $this->getJson('/api/quotes/summary')
        ->assertOk()
        ->assertJson(['active' => 0, 'awaiting' => 0, 'in_production' => 0, 'completed' => 0, 'total' => 0])
        ->assertJsonCount(0, 'awaiting_orders');
});

// M5/M6: the buyer's "waiting on you" bucket must include the states where the
// buyer actually has to act - accepting the price (ARTWORK_APPROVED) and, when
// pay-now is on, paying (PROOF_APPROVED).

it('counts an artwork-approved order as awaiting the buyer', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);

    Quote::factory()->create(['company_id' => $company->id, 'state' => 'ARTWORK_APPROVED']);

    Sanctum::actingAs($buyer);

    $this->getJson('/api/quotes/summary')
        ->assertOk()
        ->assertJson(['awaiting' => 1])
        ->assertJsonCount(1, 'awaiting_orders');
});

it('counts a proof-approved order as awaiting only when pay-now is enabled', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    Quote::factory()->create(['company_id' => $company->id, 'state' => 'PROOF_APPROVED']);

    Sanctum::actingAs($buyer);

    // Pay-now off (default, B2B): the order waits on staff to invoice, not the buyer.
    $this->getJson('/api/quotes/summary')->assertOk()->assertJson(['awaiting' => 0]);

    // Pay-now on (B2C): the buyer must pay, so it is awaiting them.
    PricingConfig::updateOrCreate(
        ['group' => 'config', 'key' => 'pay_now_cutoff'],
        ['value' => ['b2c_enabled' => true]],
    );

    $this->getJson('/api/quotes/summary')->assertOk()->assertJson(['awaiting' => 1]);
});
