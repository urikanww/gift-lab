<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Line-item procurement lifecycle (spec 5.2). A job enters the queue only when
 * every line reaches Ready or Dropped; one failed line never kills the others.
 */
enum LineItemState: string
{
    case Pending = 'PENDING';
    case Procuring = 'PROCURING';
    case Purchased = 'PURCHASED';
    case Inbound = 'INBOUND';
    case Received = 'RECEIVED';
    case Ready = 'READY';
    case AwaitingReconfirm = 'AWAITING_RECONFIRM';
    case Amended = 'AMENDED';
    case Dropped = 'DROPPED';
    case Cancelled = 'CANCELLED';

    /**
     * @return array<int, self>
     */
    public function nextStates(): array
    {
        return match ($this) {
            self::Pending => [self::Procuring, self::Cancelled],
            self::Procuring => [self::Purchased, self::AwaitingReconfirm, self::Cancelled],
            self::Purchased => [self::Inbound],
            self::Inbound => [self::Received],
            self::Received => [self::Ready],
            self::AwaitingReconfirm => [self::Amended, self::Purchased, self::Dropped, self::Cancelled],
            self::Amended => [self::Procuring],
            self::Ready, self::Dropped, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    /**
     * A line no longer blocks its job from queueing once it is Ready or Dropped.
     */
    public function isResolvedForQueue(): bool
    {
        return $this === self::Ready || $this === self::Dropped;
    }
}
