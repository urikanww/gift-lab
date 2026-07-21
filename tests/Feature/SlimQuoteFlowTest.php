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

// Was: 'stamps acceptance when a buyer approves a slim-path proof'. Approving
// artwork used to back-fill acceptance, so a buyer could be committed to a price
// they were never shown and there was no record of them having seen it. The two
// approvals are now separate acts.
it('does not treat artwork approval as agreeing the price', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png']);

    $proof = $quote->fresh()->proofs()->first();
    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'approve'])->assertOk();

    $quote->refresh();
    expect($quote->state->value)->toBe('ARTWORK_APPROVED')
        ->and($quote->accepted_at)->toBeNull()
        ->and($quote->accepted_by)->toBeNull();
});

it('completes the pair when the buyer then agrees the price', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png']);
    $proof = $quote->fresh()->proofs()->first();

    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'approve'])->assertOk();
    $this->postJson("/api/quotes/{$quote->id}/accept")->assertOk();

    $quote->refresh();
    expect($quote->state->value)->toBe('PROOF_APPROVED')
        ->and($quote->accepted_at)->not->toBeNull()
        ->and($quote->accepted_by)->toBe($buyer->id);
});

// The guard behind the split: even if a quote somehow reached PROOF_APPROVED
// without an agreed price, invoicing must refuse rather than bill for it.
it('refuses to invoice an order the buyer never priced', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create(['state' => 'PROOF_APPROVED', 'accepted_at' => null]);
    Laravel\Sanctum\Sanctum::actingAs($staff);

    $this->postJson("/api/quotes/{$quote->id}/invoice", ['po_ref' => 'PO-1'])
        ->assertStatus(422);

    expect($quote->fresh()->state->value)->toBe('PROOF_APPROVED');
});

it('routes a slim-path request-changes to CHANGES_REQUESTED', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png']);
    $proof = $quote->fresh()->proofs()->first();

    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes', 'notes' => 'too pricey'])->assertOk();

    expect($quote->refresh()->state->value)->toBe('CHANGES_REQUESTED');
});

it('keeps an accepted quote in PROOFING on request-changes (existing behavior)', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'ACCEPTED', 'accepted_at' => now(), 'accepted_by' => $buyer->id]);
    Laravel\Sanctum\Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $this->postJson("/api/quotes/{$quote->id}/proofs", ['artwork_version_ref' => 'a/v1.png']);
    $proof = $quote->fresh()->proofs()->first();

    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes', 'notes' => 'fix logo'])->assertOk();

    expect($quote->refresh()->state->value)->toBe('PROOFING');
});

// CHANGES_REQUESTED used to be unrecoverable: its only exits were DRAFT and
// CANCELLED, and no code performed the DRAFT one. An order that landed here had
// to be cancelled and rebuilt from scratch. Issuing a revised proof is the way
// out, and it is what staff would reach for anyway.
it('recovers a CHANGES_REQUESTED order by issuing a revised proof', function (): void {
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    $staff = User::factory()->staffAdmin()->create();
    Laravel\Sanctum\Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/send", ['artwork_version_ref' => 'a/v1.png']);
    $proof = $quote->fresh()->proofs()->first();

    Laravel\Sanctum\Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes', 'notes' => 'too pricey'])->assertOk();
    expect($quote->fresh()->state->value)->toBe('CHANGES_REQUESTED');

    // The recovery: a v2 proof puts the order back in front of the buyer.
    Laravel\Sanctum\Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/proofs", ['artwork_version_ref' => 'a/v2.png'])->assertCreated();

    expect($quote->fresh()->state->value)->toBe('PROOFING')
        ->and($quote->fresh()->proofs()->count())->toBe(2);
});
