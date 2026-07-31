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
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'ACCEPTED', 'accepted_at' => now()]);
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

it('auto-stages the buyer designer art as a DRAFT proof for eligible lines only', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);

    // Eligible: designer line with the buyer's own artwork_ref, no proof yet.
    $designer = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/buyer.png'],
        'line_state' => 'PENDING',
    ]);
    // Not eligible: buyer_uploaded is a brief staff must draw, never auto-staged.
    $uploaded = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'buyer_uploaded', 'reference_refs' => ['ref/a.png']],
        'line_state' => 'PENDING',
    ]);
    // Not eligible: plain stock line takes no proof at all.
    $stock = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => null,
        'line_state' => 'PENDING',
    ]);

    $this->postJson("/api/quotes/{$quote->id}/proofs/auto-stage")
        ->assertOk()
        ->assertJsonPath('staged', 1);

    $draft = Proof::where('line_item_id', $designer->id)->first();
    expect($draft)->not->toBeNull()
        ->and($draft->state->value)->toBe('DRAFT')
        ->and($draft->artwork_version_ref)->toBe('artwork/buyer.png');
    expect(Proof::where('line_item_id', $uploaded->id)->exists())->toBeFalse();
    expect(Proof::where('line_item_id', $stock->id)->exists())->toBeFalse();
});

it('auto-stage is idempotent and never clobbers an existing proof', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROOFING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/buyer.png'],
        'line_state' => 'PENDING',
    ]);
    // A proof already sent to the buyer must be left untouched.
    $sent = Proof::factory()->create([
        'quote_id' => $quote->id,
        'line_item_id' => $line->id,
        'state' => 'SENT',
        'artwork_version_ref' => 'proofs/v1.pdf',
    ]);

    $this->postJson("/api/quotes/{$quote->id}/proofs/auto-stage")
        ->assertOk()
        ->assertJsonPath('staged', 0);

    expect(Proof::where('line_item_id', $line->id)->count())->toBe(1)
        ->and($sent->fresh()->state->value)->toBe('SENT');
});

it('blocks a buyer from auto-staging proofs', function (): void {
    Sanctum::actingAs($this->buyer);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);

    $this->postJson("/api/quotes/{$quote->id}/proofs/auto-stage")->assertForbidden();
});

it('removes a staged DRAFT proof and keeps sent proofs intact', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
    $draft = Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $line->id, 'state' => 'DRAFT']);

    $this->deleteJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs")->assertOk();

    expect(Proof::where('line_item_id', $line->id)->exists())->toBeFalse()
        ->and(Proof::withTrashed()->find($draft->id)->trashed())->toBeTrue();
});

it('never removes a proof the buyer has already seen (SENT)', function (): void {
    Sanctum::actingAs($this->staff);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'PROOFING']);
    $line = LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
    Proof::factory()->create(['quote_id' => $quote->id, 'line_item_id' => $line->id, 'state' => 'SENT']);

    $this->deleteJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs")->assertOk();

    expect(Proof::where('line_item_id', $line->id)->where('state', 'SENT')->exists())->toBeTrue();
});

it('blocks a buyer from removing a staged proof', function (): void {
    Sanctum::actingAs($this->buyer);
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'DRAFT']);
    $line = LineItem::factory()->create(['quote_id' => $quote->id, 'line_state' => 'PENDING']);

    $this->deleteJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs")->assertForbidden();
});
