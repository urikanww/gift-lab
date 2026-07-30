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
