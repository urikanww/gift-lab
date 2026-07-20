<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('assigns a unique opaque reference on create', function (): void {
    $a = Quote::factory()->create();
    $b = Quote::factory()->create();

    expect($a->reference)->toBeString()->toHaveLength(10)
        ->and($a->reference)->not->toBe($b->reference);
});

it('fetches an order by its reference (no id in the URL)', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs($buyer);

    $this->getJson("/api/quotes/{$quote->reference}")
        ->assertOk()
        ->assertJsonPath('data.reference', $quote->reference)
        ->assertJsonPath('data.id', $quote->id);
});

it('still resolves by numeric id for internal callers', function (): void {
    $company = Company::factory()->create();
    $buyer = User::factory()->create(['company_id' => $company->id, 'role' => 'buyer']);
    $quote = Quote::factory()->create(['company_id' => $company->id]);
    Sanctum::actingAs($buyer);

    $this->getJson("/api/quotes/{$quote->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $quote->id);
});

it('keeps tenancy: a buyer cannot fetch another company’s order by reference', function (): void {
    $mine = User::factory()->create(['company_id' => Company::factory()->create()->id, 'role' => 'buyer']);
    $theirs = Quote::factory()->create(['company_id' => Company::factory()->create()->id]);
    Sanctum::actingAs($mine);

    $this->getJson("/api/quotes/{$theirs->reference}")->assertForbidden();
});
