<?php

declare(strict_types=1);

use App\Enums\ProofState;

it('lets a draft be sent and a sent proof be decided', function (): void {
    expect(ProofState::Draft->canTransitionTo(ProofState::Sent))->toBeTrue()
        ->and(ProofState::Sent->canTransitionTo(ProofState::Approved))->toBeTrue()
        ->and(ProofState::Sent->canTransitionTo(ProofState::ChangesRequested))->toBeTrue();
});

it('never sends a draft straight to approved', function (): void {
    expect(ProofState::Draft->canTransitionTo(ProofState::Approved))->toBeFalse();
});
