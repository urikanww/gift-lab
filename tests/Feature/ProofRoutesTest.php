<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\LineItem;
use App\Models\Proof;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

/** A customized line that takes a proof. */
function routeCustomizedLine(Quote $quote, string $ref = 'artwork/x.png'): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => $ref],
        'line_state' => 'PENDING',
    ]);
}

beforeEach(function (): void {
    Mail::fake();
    $this->company = Company::factory()->create();
    $this->buyer = User::factory()->create(['company_id' => $this->company->id, 'role' => 'buyer']);
    $this->staff = User::factory()->staffAdmin()->create();
});

it('stages a line proof, then sends the round into PROOFING', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'ACCEPTED',
        'accepted_at' => now(),
        'accepted_by' => $this->buyer->id,
    ]);
    $line = routeCustomizedLine($quote);

    Sanctum::actingAs($this->staff);

    // Staging leaves a DRAFT proof and the order untouched - nothing is sent yet.
    $this->postJson("/api/quotes/{$quote->id}/lines/{$line->id}/proofs", [
        'artwork_version_ref' => 'artwork/v1.png',
    ])->assertCreated()->assertJsonPath('data.state', 'DRAFT');

    expect($quote->fresh()->state->value)->toBe('ACCEPTED')
        ->and($line->proofs()->count())->toBe(1);

    // Sending the round flips the draft to SENT and moves the order to PROOFING.
    $this->postJson("/api/quotes/{$quote->id}/proofs/send")->assertOk();

    expect($quote->fresh()->state->value)->toBe('PROOFING')
        ->and($line->proofs()->first()->state->value)->toBe('SENT');
});

it('approves every open proof on a two-line order in one action', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'accepted_at' => null,
    ]);
    $lineA = routeCustomizedLine($quote, 'artwork/a.png');
    $lineB = routeCustomizedLine($quote, 'artwork/b.png');
    $proofA = Proof::factory()->forLine($lineA)->create(['state' => 'SENT']);
    $proofB = Proof::factory()->forLine($lineB)->create(['state' => 'SENT']);

    Sanctum::actingAs($this->buyer);

    $this->postJson("/api/quotes/{$quote->id}/proofs/approve-all")->assertOk();

    // Artwork-first order (never priced): all lines approved -> ARTWORK_APPROVED,
    // not PROOF_APPROVED, since the buyer has not agreed the price.
    expect($quote->fresh()->state->value)->toBe('ARTWORK_APPROVED')
        ->and($proofA->fresh()->state->value)->toBe('APPROVED')
        ->and($proofA->fresh()->approved_by)->toBe($this->buyer->id)
        ->and($proofB->fresh()->state->value)->toBe('APPROVED');
});

it('forbids a non-superadmin staff_admin from approving on the buyer behalf', function (): void {
    $quote = Quote::factory()->create([
        'company_id' => $this->company->id,
        'state' => 'PROOFING',
        'accepted_at' => null,
    ]);
    $line = routeCustomizedLine($quote, 'artwork/a.png');
    $proof = Proof::factory()->forLine($line)->create(['state' => 'SENT']);

    // On-behalf approval is a superadmin action; a plain staff_admin is blocked.
    Sanctum::actingAs($this->staff);
    $this->postJson("/api/quotes/{$quote->id}/proofs/approve-all")->assertStatus(403);
    expect($proof->fresh()->state->value)->toBe('SENT');

    // The owning-company buyer signs off their own order.
    Sanctum::actingAs($this->buyer);
    $this->postJson("/api/quotes/{$quote->id}/proofs/approve-all")->assertOk();
    expect($proof->fresh()->state->value)->toBe('APPROVED')
        ->and($quote->fresh()->state->value)->toBe('ARTWORK_APPROVED');
});

it('no longer exposes the order-level proof-issue route', function (): void {
    $quote = Quote::factory()->create(['company_id' => $this->company->id, 'state' => 'ACCEPTED']);
    Sanctum::actingAs($this->staff);

    // The order-level path no longer matches any route (no GET/POST bound to it),
    // so it 404s rather than 405-ing - there is no surviving verb to report.
    $this->postJson("/api/quotes/{$quote->id}/proofs", [
        'artwork_version_ref' => 'artwork/v1.png',
    ])->assertStatus(404);
});
