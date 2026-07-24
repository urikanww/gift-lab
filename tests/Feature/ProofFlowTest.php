<?php

declare(strict_types=1);

use App\Events\ProofStatusChanged;
use App\Exceptions\DomainRuleException;
use App\Models\Company;
use App\Models\LineItem;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
});

it('stages a line proof and sends the round into proofing', function (): void {
    Event::fake([ProofStatusChanged::class]);
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'ACCEPTED']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);

    // Staging leaves the order untouched; sending the round moves it to PROOFING.
    $this->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", [
        'artwork_version_ref' => 'proofs/v1.pdf',
    ])->assertCreated()->assertJsonPath('data.version', 1);
    expect($quote->fresh()->state->value)->toBe('ACCEPTED');

    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();

    expect($quote->fresh()->state->value)->toBe('PROOFING');
    Event::assertDispatched(ProofStatusChanged::class);
});

it('records an immutable approval and advances the quote', function (): void {
    Sanctum::actingAs($this->buyer);
    // Price-first route: the buyer agreed the price before proofing began, so
    // approving the artwork completes both approvals. accepted_at is what marks
    // this as that route - without it the order is artwork-first and artwork
    // approval alone must NOT carry it to PROOF_APPROVED.
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'accepted_at' => now(),
        'accepted_by' => $this->buyer->id,
    ]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
    $proof = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $line->id, 'state' => 'SENT']);

    $this->postJson("/api/proofs/{$proof->id}/decide", ['decision' => 'approve'])->assertOk();

    $proof->refresh();
    expect($proof->state->value)->toBe('APPROVED')
        ->and($proof->approved_by)->toBe($this->buyer->id)
        ->and($proof->approved_at)->not->toBeNull()
        ->and($quote->fresh()->state->value)->toBe('PROOF_APPROVED');
});

it('prevents mutating an approved proof', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROOFING']);
    $proof = Proof::factory()->approved()->create(['quote_id' => $quote->id]);

    expect(fn () => $proof->update(['notes' => 'tampered']))->toThrow(DomainRuleException::class);
});

it('lets a buyer request changes without approving', function (): void {
    Sanctum::actingAs($this->buyer);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROOFING', 'accepted_at' => now(), 'accepted_by' => $this->buyer->id]);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
    $proof = Proof::factory()->forLine($line)->create(['state' => 'SENT']);

    $this->postJson("/api/proofs/{$proof->id}/decide", [
        'decision' => 'request_changes',
        'notes' => 'Move the logo up.',
    ])->assertOk();

    expect($proof->fresh()->state->value)->toBe('CHANGES_REQUESTED');

    // A revised proof can still be staged on the same line and sent as v2.
    Sanctum::actingAs($this->staff);
    $this->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", [
        'artwork_version_ref' => 'proofs/v2.pdf',
    ])->assertCreated()->assertJsonPath('data.version', 2);
    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();

    expect($quote->fresh()->state->value)->toBe('PROOFING');
});
