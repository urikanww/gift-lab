<?php

declare(strict_types=1);

use App\Exceptions\InvalidStateTransitionException;
use App\Models\LineItem;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/**
 * The "slim path" was a single-artwork send that created one order-level proof.
 * That path is retired: proofing now goes through per-line stage -> send, and
 * the buyer decides per line. These cases preserve the original intents,
 * re-expressed through the per-line flow.
 */

/** A customized line on the quote that takes a proof. */
function slimLine(Quote $quote, string $ref = 'artwork/x.png'): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => $ref],
        'line_state' => 'PENDING',
    ]);
}

/** Staff stage one line and send the round in one email. */
function stageAndSend(object $test, Quote $quote, LineItem $line, string $ref): void
{
    $staff = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($staff);
    $test->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", ['artwork_version_ref' => $ref])->assertCreated();
    $test->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();
}

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
    // L2: an explicit domain guard now rejects accept on a non-acceptable state
    // (DRAFT here) with a clean message, before the state machine is touched.
    expect(fn () => app(QuoteService::class)->accept($quote))
        ->toThrow(App\Exceptions\DomainRuleException::class);

    $fresh = $quote->fresh();
    expect($fresh->accepted_at)->toBeNull()
        ->and($fresh->accepted_by)->toBeNull()
        ->and($fresh->state->value)->toBe('DRAFT');
});

it('stages a line proof and sends the round into PROOFING', function (): void {
    Mail::fake();
    $quote = Quote::factory()->proofFirst()->create(['state' => 'DRAFT']);
    $line = slimLine($quote);

    stageAndSend($this, $quote, $line, 'artwork/v1-key.png');

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
    Mail::fake();
    Sanctum::actingAs(User::factory()->staffAdmin()->create());

    $this->postJson("/api/quotes/{$quote->id}/send")
        ->assertOk()->assertJsonPath('data.state', 'SENT');
});

// Was: 'stamps acceptance when a buyer approves a slim-path proof'. Approving
// artwork used to back-fill acceptance, so a buyer could be committed to a price
// they were never shown and there was no record of them having seen it. The two
// approvals are now separate acts.
it('does not treat artwork approval as agreeing the price', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    $line = slimLine($quote);
    stageAndSend($this, $quote, $line, 'a/v1.png');
    $proof = $quote->fresh()->proofs()->first();

    Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'approve'])->assertOk();

    $quote->refresh();
    expect($quote->state->value)->toBe('ARTWORK_APPROVED')
        ->and($quote->accepted_at)->toBeNull()
        ->and($quote->accepted_by)->toBeNull();
});

it('completes the pair when the buyer then agrees the price', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    $line = slimLine($quote);
    stageAndSend($this, $quote, $line, 'a/v1.png');
    $proof = $quote->fresh()->proofs()->first();

    Sanctum::actingAs($buyer);
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
    Sanctum::actingAs($staff);

    $this->postJson("/api/quotes/{$quote->id}/invoice", ['po_ref' => 'PO-1'])
        ->assertStatus(422);

    expect($quote->fresh()->state->value)->toBe('PROOF_APPROVED');
});

it('routes a per-line request-changes to CHANGES_REQUESTED', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    $line = slimLine($quote);
    stageAndSend($this, $quote, $line, 'a/v1.png');
    $proof = $quote->fresh()->proofs()->first();

    Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes', 'notes' => 'too pricey'])->assertOk();

    // The sole artwork line was rejected, so the whole order rolls to CHANGES_REQUESTED.
    expect($quote->refresh()->state->value)->toBe('CHANGES_REQUESTED');
});

// The rollup keeps the order in PROOFING while any line still awaits the buyer:
// a change request on one line of a multi-line round does not pull the whole
// order back, because the other line is still in front of the buyer.
it('keeps a multi-line order in PROOFING when only one line requests changes', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->create(['company_id' => $buyer->company_id, 'state' => 'ACCEPTED', 'accepted_at' => now(), 'accepted_by' => $buyer->id]);
    $lineA = slimLine($quote, 'a/a.png');
    $lineB = slimLine($quote, 'a/b.png');

    $staff = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/lines/{$lineA->id}/proofs", ['artwork_version_ref' => 'a/a-v1.png'])->assertCreated();
    $this->postJson("/api/quotes/{$quote->id}/lines/{$lineB->id}/proofs", ['artwork_version_ref' => 'a/b-v1.png'])->assertCreated();
    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();
    expect($quote->fresh()->state->value)->toBe('PROOFING');

    $proofB = $lineB->proofs()->first();
    Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proofB->id}/decide", ['decision' => 'request_changes', 'notes' => 'fix logo'])->assertOk();

    // Line A's proof is still SENT (awaiting the buyer), so the order holds at PROOFING.
    expect($quote->refresh()->state->value)->toBe('PROOFING');
});

// CHANGES_REQUESTED is recoverable: staging a revised proof and sending it puts
// the order back in front of the buyer, rather than forcing a cancel-and-rebuild.
it('recovers a CHANGES_REQUESTED order by staging a revised proof', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create(['company_id' => $buyer->company_id, 'state' => 'DRAFT', 'accepted_at' => null]);
    $line = slimLine($quote);
    stageAndSend($this, $quote, $line, 'a/v1.png');
    $proof = $quote->fresh()->proofs()->first();

    Sanctum::actingAs($buyer);
    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'request_changes', 'notes' => 'too pricey'])->assertOk();
    expect($quote->fresh()->state->value)->toBe('CHANGES_REQUESTED');

    // The recovery: a v2 proof on the changed line puts the order back in front
    // of the buyer.
    $staff = User::factory()->staffAdmin()->create();
    Sanctum::actingAs($staff);
    $this->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", ['artwork_version_ref' => 'a/v2.png'])->assertCreated();
    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();

    expect($quote->fresh()->state->value)->toBe('PROOFING')
        ->and($quote->fresh()->proofs()->count())->toBe(2);
});
