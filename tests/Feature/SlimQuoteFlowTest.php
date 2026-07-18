<?php

declare(strict_types=1);

use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;

it('stamps accepted_at and accepted_by when a buyer accepts', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'SENT']);

    $this->actingAs($buyer);
    app(QuoteService::class)->accept($quote);

    $quote->refresh();
    expect($quote->accepted_at)->not->toBeNull()
        ->and($quote->accepted_by)->toBe($buyer->id);
});

it('does not stamp acceptance when accept is called on an illegal state', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT']);

    $this->actingAs($buyer);
    expect(fn () => app(QuoteService::class)->accept($quote))
        ->toThrow(App\Exceptions\InvalidStateTransitionException::class);

    $fresh = $quote->fresh();
    expect($fresh->accepted_at)->toBeNull()
        ->and($fresh->accepted_by)->toBeNull()
        ->and($fresh->state->value)->toBe('DRAFT');
});

it('sends a quote with a proof and lands in PROOFING', function (): void {
    $quote = Quote::factory()->create(['state' => 'DRAFT']);
    \Illuminate\Support\Facades\Mail::fake();
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send", [
        'artwork_version_ref' => 'artwork/v1-key.png',
    ])->assertOk()->assertJsonPath('data.state', 'PROOFING');

    $quote->refresh();
    expect($quote->state->value)->toBe('PROOFING')
        ->and($quote->proofs()->count())->toBe(1)
        ->and($quote->proofs()->first()->version)->toBe(1);

    $proof = $quote->proofs()->first();
    expect($proof->artwork_version_ref)->toBe('artwork/v1-key.png')
        ->and($proof->state->value)->toBe('SENT')
        ->and($quote->price_snapshot_at)->not->toBeNull();
});

it('sends a quote without a proof and lands in SENT', function (): void {
    $quote = Quote::factory()->create(['state' => 'DRAFT']);
    \Illuminate\Support\Facades\Mail::fake();
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")
        ->assertOk()->assertJsonPath('data.state', 'SENT');
});
