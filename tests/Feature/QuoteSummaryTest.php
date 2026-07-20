<?php

declare(strict_types=1);

use App\Models\Company;
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
