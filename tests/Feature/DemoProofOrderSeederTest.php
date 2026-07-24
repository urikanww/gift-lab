<?php

declare(strict_types=1);

use App\Enums\ProofState;
use App\Enums\QuoteState;
use App\Models\LineItem;
use App\Models\Quote;
use App\Models\User;
use Database\Seeders\DemoProofOrderSeeder;

/**
 * Light guard on the demo seeder: it must run clean and produce the mixed-state
 * proof order the manual/demo walkthrough relies on - three customized lines
 * whose latest proofs are APPROVED / SENT / CHANGES_REQUESTED, with the rollup
 * resting on PROOFING.
 */
function latestProofStateForLine(LineItem $line): ProofState
{
    return $line->proofs()->orderByDesc('version')->firstOrFail()->state;
}

it('seeds a mixed-state proofing order plus the buyer login', function (): void {
    (new DemoProofOrderSeeder)->run();

    // Fixed, known buyer credentials the demo logs in with.
    $buyer = User::where('email', 'demo-buyer@example.test')->first();
    expect($buyer)->not->toBeNull()
        ->and($buyer->role->value)->toBe('buyer');

    $quote = Quote::where('state', QuoteState::Proofing->value)
        ->where('company_id', $buyer->company_id)
        ->firstOrFail();

    $lines = $quote->lineItems()->get();
    expect($lines)->toHaveCount(3);
    $lines->each(fn (LineItem $line) => expect($line->needsProof())->toBeTrue());

    // Each line's latest proof sits in a different state.
    $states = $lines
        ->map(fn (LineItem $line) => latestProofStateForLine($line)->value)
        ->sort()
        ->values()
        ->all();

    expect($states)->toBe(['APPROVED', 'CHANGES_REQUESTED', 'SENT']);

    // The APPROVED line has a prior CHANGES_REQUESTED version (history), and the
    // CHANGES_REQUESTED line carries a buyer note.
    $approvedLine = $lines->first(
        fn (LineItem $line) => latestProofStateForLine($line) === ProofState::Approved
    );
    expect($approvedLine->proofs()->count())->toBe(2)
        ->and($approvedLine->proofs()->where('state', ProofState::ChangesRequested->value)->exists())->toBeTrue();

    $changesLine = $lines->first(
        fn (LineItem $line) => latestProofStateForLine($line) === ProofState::ChangesRequested
    );
    expect($changesLine->proofs()->orderByDesc('version')->first()->notes)->not->toBeEmpty();
});

it('rolls up to PROOFING and stays there on recompute', function (): void {
    (new DemoProofOrderSeeder)->run();

    $quote = Quote::where('state', QuoteState::Proofing->value)->firstOrFail();

    $quote->recomputeProofState();
    $quote->refresh();

    expect($quote->state)->toBe(QuoteState::Proofing);
});

it('is idempotent across repeated runs', function (): void {
    (new DemoProofOrderSeeder)->run();
    (new DemoProofOrderSeeder)->run();

    expect(User::where('email', 'demo-buyer@example.test')->count())->toBe(1)
        ->and(Quote::where('state', QuoteState::Proofing->value)->count())->toBe(1)
        ->and(Quote::where('state', QuoteState::ArtworkApproved->value)->count())->toBe(1);
});
