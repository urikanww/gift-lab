<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Quote lifecycle (spec 5.1). canTransitionTo() is the single source of truth
 * for legal moves; models and Form Requests both consult it so an illegal
 * transition is impossible regardless of entry point.
 */
enum QuoteState: string
{
    case Draft = 'DRAFT';
    case Sent = 'SENT';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Accepted = 'ACCEPTED';
    case Proofing = 'PROOFING';
    case ProofApproved = 'PROOF_APPROVED';
    case Invoiced = 'INVOICED';
    case Confirmed = 'CONFIRMED';
    case Procuring = 'PROCURING';
    case Ready = 'READY';
    case Closed = 'CLOSED';
    case Cancelled = 'CANCELLED';

    /**
     * @return array<int, self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Proofing, self::Cancelled],
            self::Sent => [self::ChangesRequested, self::Accepted, self::Cancelled],
            self::ChangesRequested => [self::Draft, self::Cancelled],
            self::Accepted => [self::Proofing, self::Cancelled],
            self::Proofing => [self::ProofApproved, self::ChangesRequested, self::Cancelled],
            self::ProofApproved => [self::Invoiced, self::Cancelled],
            self::Invoiced => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Procuring, self::Cancelled],
            self::Procuring => [self::Ready, self::Cancelled],
            // Once on the floor (READY) the order is in production - no cancel edge.
            self::Ready => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    public function isTerminal(): bool
    {
        return $this->nextStates() === [];
    }
}
