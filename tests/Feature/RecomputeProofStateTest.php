<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use App\Models\Product;
use App\Models\Proof;
use App\Models\Quote;

function customizedLine(Quote $quote): LineItem
{
    return LineItem::factory()->create([
        'quote_id' => $quote->id,
        'product_id' => Product::factory()->create()->id,
        'customization' => ['mode' => 'designer', 'artwork_ref' => 'artwork/a.png'],
        'line_state' => 'PENDING',
    ]);
}

function proofFor(LineItem $line, ProofState $state, int $version = 1): Proof
{
    return Proof::factory()->create([
        'quote_id' => $line->quote_id,
        'line_item_id' => $line->id,
        'version' => $version,
        'state' => $state->value,
    ]);
}

it('stays in proofing while any artwork line still awaits the buyer', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING']);
    $a = customizedLine($quote);
    $b = customizedLine($quote);
    proofFor($a, ProofState::Approved);
    proofFor($b, ProofState::Sent);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);
});

it('reports changes-requested when nothing awaits the buyer but a line needs revision', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING']);
    $a = customizedLine($quote);
    $b = customizedLine($quote);
    proofFor($a, ProofState::Approved);
    proofFor($b, ProofState::ChangesRequested);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ChangesRequested);
});

it('advances to ARTWORK_APPROVED once every artwork line is approved (price not yet accepted)', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    proofFor(customizedLine($quote), ProofState::Approved);
    proofFor(customizedLine($quote), ProofState::Approved);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});

it('advances to PROOF_APPROVED once every artwork line is approved and the price was accepted', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => now()]);
    proofFor(customizedLine($quote), ProofState::Approved);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ProofApproved);
});

it('excludes dropped lines from the gate', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    proofFor(customizedLine($quote), ProofState::Approved);
    $dropped = customizedLine($quote);
    $dropped->update(['line_state' => 'DROPPED']);
    proofFor($dropped, ProofState::ChangesRequested);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});

it('reads the latest proof version per line, not an earlier one', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING', 'accepted_at' => null]);
    $line = customizedLine($quote);
    proofFor($line, ProofState::ChangesRequested, 1);
    proofFor($line, ProofState::Approved, 2);

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::ArtworkApproved);
});

it('stays in proofing when a customized line has no proof prepared yet', function (): void {
    $quote = Quote::factory()->create(['state' => 'PROOFING']);
    proofFor(customizedLine($quote), ProofState::Approved);
    customizedLine($quote); // no proof staged

    $quote->recomputeProofState();

    expect($quote->fresh()->state)->toBe(QuoteState::Proofing);
});
