<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lets staff cancel a quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel", ['reason' => 'duplicate'])
        ->assertOk()
        ->assertJsonPath('data.state', 'CANCELLED');
});

it('lets a superadmin cancel a quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->superadmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertOk();
});

it('forbids a buyer from cancelling their own company quote', function (): void {
    $buyer = User::factory()->create(); // buyer
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'SENT']);
    Sanctum::actingAs($buyer);

    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertStatus(403);
    expect($quote->refresh()->state->value)->toBe('SENT');
});

it('refuses to cancel a READY quote', function (): void {
    $quote = Quote::factory()->create(['state' => 'READY']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    // transitionTo throws InvalidStateTransitionException, mapped to 422 by the
    // handler in bootstrap/app.php; the DB::transaction rolls back so state stays.
    $this->postJson("/api/quotes/{$quote->id}/cancel")->assertStatus(422);
    expect($quote->refresh()->state->value)->toBe('READY');
});

it('validates the reason length', function (): void {
    $quote = Quote::factory()->create(['state' => 'SENT']);
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/cancel", ['reason' => str_repeat('x', 501)])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});
