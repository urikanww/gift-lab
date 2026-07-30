<?php

declare(strict_types=1);

use App\Enums\ApprovalOrder;
use App\Enums\QuoteState;
use App\Exceptions\DomainRuleException;
use App\Models\LineItem;
use App\Models\Quote;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

it('defaults a new quote to price_first', function (): void {
    $quote = Quote::factory()->create();

    expect($quote->approval_order)->toBe(ApprovalOrder::PriceFirst);
});

it('persists proof_first via the factory state', function (): void {
    $quote = Quote::factory()->proofFirst()->create();

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

it('lets staff set approval_order on a DRAFT quote', function (): void {
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);

    app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

it('refuses to change approval_order once sent for ordinary staff', function (): void {
    $staff = User::factory()->staffAdmin()->create();
    Auth::login($staff);
    $quote = Quote::factory()->sent()->create();

    expect(fn () => app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::PriceFirst);
});

it('lets a superadmin change approval_order after send', function (): void {
    $admin = User::factory()->superadmin()->create();
    Auth::login($admin);
    $quote = Quote::factory()->sent()->create();

    app(QuoteService::class)->setApprovalOrder($quote, ApprovalOrder::ProofFirst);

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

/** A customized line that needs a proof. */
function proofLine(Quote $quote): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/x.png'],
        'line_state' => 'PENDING',
    ]);
}

it('price_first: blocks sending proofs before the price is accepted', function (): void {
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);
    $line = proofLine($quote);
    app(QuoteService::class)->stageProof($quote, $line, 'artwork/v1.png');

    expect(fn () => app(QuoteService::class)->sendProofs($quote))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->state)->toBe(QuoteState::Draft);
});

it('proof_first: blocks the plain price send when there are proof lines', function (): void {
    $quote = Quote::factory()->proofFirst()->create(['state' => QuoteState::Draft->value]);
    proofLine($quote);

    expect(fn () => app(QuoteService::class)->send($quote))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->state)->toBe(QuoteState::Draft);
});

it('proof_first: blocks price acceptance from SENT with proof lines', function (): void {
    // Force SENT (bypassing the send() guard) to prove accept() also refuses.
    $quote = Quote::factory()->proofFirst()->sent()->create();
    proofLine($quote);

    expect(fn () => app(QuoteService::class)->accept($quote))
        ->toThrow(DomainRuleException::class);

    expect($quote->fresh()->accepted_at)->toBeNull();
});

it('proof_first: Draft -> Proofing -> ArtworkApproved -> accept -> ProofApproved', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $quote = Quote::factory()->proofFirst()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::Draft->value,
        'accepted_at' => null,
    ]);
    $line = proofLine($quote);
    $svc = app(QuoteService::class);

    $svc->stageProof($quote, $line, 'artwork/v1.png');
    $svc->sendProofs($quote);
    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);

    Auth::login($buyer);
    $proof = $quote->fresh()->proofs()->first();
    $svc->approveProof($proof);
    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);

    $svc->accept($quote->fresh());
    $fresh = $quote->fresh();
    expect($fresh->state)->toBe(QuoteState::ProofApproved)
        ->and($fresh->accepted_at)->not->toBeNull();
});

it('price_first: Draft -> Sent -> accept -> Proofing -> approve -> ProofApproved', function (): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::Draft->value,
    ]);
    $line = proofLine($quote);
    $svc = app(QuoteService::class);

    Auth::login($staff);
    $svc->send($quote);
    expect($quote->fresh()->state)->toBe(QuoteState::Sent);

    Auth::login($buyer);
    $svc->accept($quote->fresh());
    expect($quote->fresh()->state)->toBe(QuoteState::Accepted);

    Auth::login($staff);
    $svc->stageProof($quote->fresh(), $line, 'artwork/v1.png');
    $svc->sendProofs($quote->fresh());
    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);

    Auth::login($buyer);
    $svc->approveProof($quote->fresh()->proofs()->first());
    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);
});

it('plain-stock is a no-op under both orderings', function (string $order): void {
    Mail::fake();
    $buyer = User::factory()->create();
    $staff = User::factory()->staffAdmin()->create();
    // No proofLine() => plain stock. Bare line, explicitly no customization.
    $quote = Quote::factory()->create([
        'company_id' => $buyer->company_id,
        'state' => QuoteState::Draft->value,
        'approval_order' => $order,
    ]);
    LineItem::factory()->create(['quote_id' => $quote->id, 'line_state' => 'PENDING', 'customization' => null]);
    $svc = app(QuoteService::class);

    Auth::login($staff);
    $svc->send($quote);            // allowed even for proof_first: nothing to proof
    expect($quote->fresh()->state)->toBe(QuoteState::Sent);

    Auth::login($buyer);
    $svc->accept($quote->fresh()); // auto-skips to PROOF_APPROVED
    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);
})->with(['price_first', 'proof_first']);

it('PATCH /approval-order updates a DRAFT quote and echoes it in the resource', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);

    $this->patchJson("/api/quotes/{$quote->id}/approval-order", ['approval_order' => 'proof_first'])
        ->assertOk()
        ->assertJsonPath('data.approval_order', 'proof_first');

    expect($quote->fresh()->approval_order)->toBe(ApprovalOrder::ProofFirst);
});

it('PATCH /approval-order rejects an invalid value', function (): void {
    Sanctum::actingAs(User::factory()->staffAdmin()->create());
    $quote = Quote::factory()->create(['state' => QuoteState::Draft->value]);

    $this->patchJson("/api/quotes/{$quote->id}/approval-order", ['approval_order' => 'nonsense'])
        ->assertStatus(422);
});
