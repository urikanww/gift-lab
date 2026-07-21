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
    /**
     * Artwork-first route only: the buyer has signed off the artwork but has
     * NOT yet agreed the price. Exists so PROOF_APPROVED can keep meaning
     * exactly one thing - both approvals are in - and so an order can never
     * reach invoicing on the strength of an artwork sign-off alone.
     */
    case ArtworkApproved = 'ARTWORK_APPROVED';
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
            // Proofing: staff issue a revised proof, which is the way out that
            // was missing. Draft: staff pull the order back to re-price it.
            // Without the first edge this state was unrecoverable and the order
            // had to be cancelled and rebuilt.
            self::ChangesRequested => [self::Draft, self::Proofing, self::Cancelled],
            self::Accepted => [self::Proofing, self::Cancelled],
            // Two exits by design. ProofApproved when the price was agreed first
            // (price-first route); ArtworkApproved when it was not, so the price
            // still has to be put to the buyer.
            self::Proofing => [self::ProofApproved, self::ArtworkApproved, self::ChangesRequested, self::Cancelled],
            // The buyer accepting the price is what completes the pair.
            self::ArtworkApproved => [self::ProofApproved, self::ChangesRequested, self::Cancelled],
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
