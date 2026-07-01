<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Proof sign-off (spec 6.3). Approved is terminal and immutable: any artwork
 * change spawns a NEW proof version rather than mutating an approved one.
 */
enum ProofState: string
{
    case Sent = 'SENT';
    case ChangesRequested = 'CHANGES_REQUESTED';
    case Approved = 'APPROVED';

    /**
     * @return array<int, self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Sent => [self::ChangesRequested, self::Approved],
            self::ChangesRequested => [],
            self::Approved => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }
}
