<?php

declare(strict_types=1);

use App\Enums\JobState;
use App\Enums\LineItemState;
use App\Enums\License;
use App\Enums\ProofState;
use App\Enums\QuoteState;

it('allows the happy-path quote transitions', function (): void {
    expect(QuoteState::Draft->canTransitionTo(QuoteState::Sent))->toBeTrue()
        ->and(QuoteState::Sent->canTransitionTo(QuoteState::Accepted))->toBeTrue()
        ->and(QuoteState::Accepted->canTransitionTo(QuoteState::Proofing))->toBeTrue()
        ->and(QuoteState::Proofing->canTransitionTo(QuoteState::ProofApproved))->toBeTrue()
        ->and(QuoteState::ProofApproved->canTransitionTo(QuoteState::Invoiced))->toBeTrue()
        ->and(QuoteState::Invoiced->canTransitionTo(QuoteState::Confirmed))->toBeTrue()
        ->and(QuoteState::Confirmed->canTransitionTo(QuoteState::Procuring))->toBeTrue()
        ->and(QuoteState::Procuring->canTransitionTo(QuoteState::Ready))->toBeTrue()
        ->and(QuoteState::Ready->canTransitionTo(QuoteState::Closed))->toBeTrue();
});

it('rejects illegal quote transitions', function (): void {
    expect(QuoteState::Draft->canTransitionTo(QuoteState::Ready))->toBeFalse()
        ->and(QuoteState::Closed->canTransitionTo(QuoteState::Draft))->toBeFalse()
        ->and(QuoteState::ProofApproved->canTransitionTo(QuoteState::Draft))->toBeFalse();
});

it('allows cancellation from any pre-production stage but not once ready', function (): void {
    expect(QuoteState::Draft->canTransitionTo(QuoteState::Cancelled))->toBeTrue()
        ->and(QuoteState::Sent->canTransitionTo(QuoteState::Cancelled))->toBeTrue()
        ->and(QuoteState::Accepted->canTransitionTo(QuoteState::Cancelled))->toBeTrue()
        ->and(QuoteState::Proofing->canTransitionTo(QuoteState::Cancelled))->toBeTrue()
        ->and(QuoteState::ProofApproved->canTransitionTo(QuoteState::Cancelled))->toBeTrue()
        ->and(QuoteState::Confirmed->canTransitionTo(QuoteState::Cancelled))->toBeTrue()
        ->and(QuoteState::Procuring->canTransitionTo(QuoteState::Cancelled))->toBeTrue()
        ->and(QuoteState::Ready->canTransitionTo(QuoteState::Cancelled))->toBeFalse()
        ->and(QuoteState::Closed->canTransitionTo(QuoteState::Cancelled))->toBeFalse();
});

it('treats closed and cancelled as terminal', function (): void {
    expect(QuoteState::Closed->isTerminal())->toBeTrue()
        ->and(QuoteState::Cancelled->isTerminal())->toBeTrue()
        ->and(QuoteState::Draft->isTerminal())->toBeFalse();
});

it('drives the line-item procurement happy path and shortfall branch', function (): void {
    expect(LineItemState::Pending->canTransitionTo(LineItemState::Procuring))->toBeTrue()
        ->and(LineItemState::Procuring->canTransitionTo(LineItemState::Purchased))->toBeTrue()
        ->and(LineItemState::Received->canTransitionTo(LineItemState::Ready))->toBeTrue()
        ->and(LineItemState::Procuring->canTransitionTo(LineItemState::AwaitingReconfirm))->toBeTrue()
        ->and(LineItemState::AwaitingReconfirm->canTransitionTo(LineItemState::Dropped))->toBeTrue()
        ->and(LineItemState::Amended->canTransitionTo(LineItemState::Procuring))->toBeTrue();
});

it('resolves a line for the queue only when ready or dropped', function (): void {
    expect(LineItemState::Ready->isResolvedForQueue())->toBeTrue()
        ->and(LineItemState::Dropped->isResolvedForQueue())->toBeTrue()
        ->and(LineItemState::Procuring->isResolvedForQueue())->toBeFalse()
        ->and(LineItemState::AwaitingReconfirm->isResolvedForQueue())->toBeFalse();
});

it('makes an approved proof terminal (immutable)', function (): void {
    expect(ProofState::Sent->canTransitionTo(ProofState::Approved))->toBeTrue()
        ->and(ProofState::Sent->canTransitionTo(ProofState::ChangesRequested))->toBeTrue()
        ->and(ProofState::Approved->nextStates())->toBe([])
        ->and(ProofState::Approved->canTransitionTo(ProofState::ChangesRequested))->toBeFalse();
});

it('advances jobs forward only', function (): void {
    expect(JobState::Ready->canTransitionTo(JobState::InProduction))->toBeTrue()
        ->and(JobState::InProduction->canTransitionTo(JobState::Shipped))->toBeTrue()
        ->and(JobState::Shipped->canTransitionTo(JobState::Closed))->toBeTrue()
        ->and(JobState::Shipped->canTransitionTo(JobState::Ready))->toBeFalse();
});

it('gates 3D licences to commercial-ok only', function (): void {
    expect(License::Cc0->isCommercialOk())->toBeTrue()
        ->and(License::CcBy->isCommercialOk())->toBeTrue()
        ->and(License::CcBySa->isCommercialOk())->toBeTrue()
        ->and(License::Gpl->isCommercialOk())->toBeTrue()
        ->and(License::Bsd->isCommercialOk())->toBeTrue()
        // NC/ND explicitly enabled by the operator (licence-risk accepted).
        ->and(License::CcByNc->isCommercialOk())->toBeTrue()
        ->and(License::CcByNd->isCommercialOk())->toBeTrue()
        ->and(License::CcByNcSa->isCommercialOk())->toBeTrue()
        ->and(License::Owned->isCommercialOk())->toBeTrue()
        ->and(License::Blocked->isCommercialOk())->toBeFalse()
        // Attribution/notice-bound licences require a credit; CC0/OWNED don't.
        ->and(License::CcBy->requiresCreatorCredit())->toBeTrue()
        ->and(License::CcBySa->requiresCreatorCredit())->toBeTrue()
        ->and(License::Gpl->requiresCreatorCredit())->toBeTrue()
        ->and(License::Cc0->requiresCreatorCredit())->toBeFalse()
        ->and(License::Owned->requiresCreatorCredit())->toBeFalse();
});

it('classifies licences into compliance tiers for superadmin labelling', function (): void {
    expect(License::Cc0->tier())->toBe('standard')
        ->and(License::CcBy->tier())->toBe('standard')
        ->and(License::Owned->tier())->toBe('standard')
        ->and(License::CcBySa->tier())->toBe('extended')
        ->and(License::Gpl->tier())->toBe('extended')
        ->and(License::Bsd->tier())->toBe('extended')
        ->and(License::CcByNc->tier())->toBe('high_risk')
        ->and(License::CcByNd->tier())->toBe('high_risk')
        ->and(License::CcByNcSa->tier())->toBe('high_risk');
});
