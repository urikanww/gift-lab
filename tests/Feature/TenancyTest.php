<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('stops a buyer from viewing another company quote', function (): void {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $mine->id, 'role' => 'buyer']);
    $foreignQuote = Quote::factory()->create(['company_id' => $theirs->id]);

    Sanctum::actingAs($buyer);
    $this->getJson("/api/quotes/{$foreignQuote->id}")->assertForbidden();
});

it('scopes the buyer quote list to their own company', function (): void {
    $mine = Company::factory()->create();
    $theirs = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $mine->id, 'role' => 'buyer']);
    Quote::factory()->count(2)->create(['company_id' => $mine->id]);
    Quote::factory()->count(3)->create(['company_id' => $theirs->id]);

    Sanctum::actingAs($buyer);
    $response = $this->getJson('/api/quotes')->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

it('lets staff view any company quote', function (): void {
    $company = Company::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['company_id' => $company->id]);

    Sanctum::actingAs($staff);
    $this->getJson("/api/quotes/{$quote->id}")->assertOk();
});
